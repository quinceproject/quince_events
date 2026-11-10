<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Events\Event;

use Configuration;
use Context;
use Quince\Engine\Settings\ConfigStore;

/**
 * Wysyłka zdarzeń sklepu na zewnątrz.
 *
 * Jeden adres, jeden podpis, jedna postać koperty — dla wszystkich modułów.
 * Po drugiej stronie stoi przepływ w n8n albo Windmillu, który rozdziela ruch
 * po rodzaju zdarzenia i robi z nim, co sklep sobie ustalił: zakłada sprawę
 * w CRM-ie, pisze na czacie zespołu, dopisuje wiersz w arkuszu.
 *
 * Trzy decyzje, które warto znać:
 *
 * **Zapis przed wysyłką.** Zdarzenie ląduje w skrzynce nadawczej, zanim
 * ruszymy z żądaniem. Przepływ po drugiej stronie bywa wyłączony, przeciążony
 * albo w trakcie wdrożenia — a zapytanie klienta nie może wtedy zniknąć.
 *
 * **Podpis, nie hasło w adresie.** Do nagłówka idzie skrót treści policzony
 * wspólnym sekretem. Odbiorca liczy go u siebie i porównuje: dzięki temu wie,
 * że koperta wyszła z tego sklepu i że nikt jej po drodze nie zmienił. Adres
 * z tajnym parametrem zostawiłby ten sekret w dziennikach każdego serwera
 * pośredniczącego.
 *
 * **Krótki czas oczekiwania.** Klient czeka na potwierdzenie wysłania
 * formularza, nie na cudzy przepływ. Po dwóch sekundach zostawiamy zdarzenie
 * w skrzynce i wracamy do klienta; wyśle się przy następnej okazji.
 */
final class EventDispatcher
{
    private const ENABLED = 'QUINCE_EVENTS_ENABLED';
    private const URL = 'QUINCE_EVENTS_URL';
    private const SECRET = 'QUINCE_EVENTS_SECRET';

    /** Nagłówki, którymi opisujemy kopertę odbiorcy. */
    public const HEADER_SIGNATURE = 'X-Quince-Signature';
    public const HEADER_EVENT = 'X-Quince-Event';
    public const HEADER_ID = 'X-Quince-Event-Id';

    private const TIMEOUT = 2;

    public function __construct(private readonly EventOutbox $outbox)
    {
    }

    public function isReady(): bool
    {
        return (string) Configuration::get(self::ENABLED) === '1'
            && $this->url() !== ''
            && $this->secret() !== '';
    }

    public function url(): string
    {
        return trim((string) Configuration::get(self::URL));
    }

    public function secret(): string
    {
        return trim((string) Configuration::get(self::SECRET));
    }

    /** @param array<string,mixed> $values */
    public function save(array $values): void
    {
        ConfigStore::set(self::ENABLED, empty($values['ENABLED']) ? '0' : '1');
        ConfigStore::set(self::URL, trim((string) ($values['URL'] ?? '')));

        // Sekret, którego sprzedawca nie wpisał, zostaje ten, który był —
        // pole pokazujemy puste, żeby nie wyświetlać go w panelu.
        $sekret = trim((string) ($values['SECRET'] ?? ''));

        if ($sekret !== '') {
            ConfigStore::set(self::SECRET, $sekret);
        }
    }

    /** Nowy sekret do wklejenia po drugiej stronie. */
    public function generateSecret(): string
    {
        $sekret = bin2hex(random_bytes(24));
        ConfigStore::set(self::SECRET, $sekret);

        return $sekret;
    }

    public function removeSettings(): void
    {
        foreach ([self::ENABLED, self::URL, self::SECRET] as $klucz) {
            Configuration::deleteByName($klucz);
        }
    }

    /**
     * Zgłoszenie zdarzenia.
     *
     * Zapis do dziennika jest BEZWARUNKOWY — dzieje się zawsze, niezależnie
     * od tego, czy magistrala do zewnętrznego odbiorcy jest w ogóle włączona.
     * Dziennik ma własnych konsumentów wewnątrz sklepu (np. moduł powiadomień),
     * którym nieskonfigurowany CEP czy n8n nic nie obchodzi — `isReady()`
     * decyduje WYŁĄCZNIE o tym, czy w ogóle próbować wysyłki na zewnątrz.
     *
     * Zwraca `true`, gdy odbiorca potwierdził przyjęcie. `false` znaczy „nie
     * teraz" (magistrala wyłączona/nieskonfigurowana, albo odbiorca nie
     * odpowiedział) — zdarzenie i tak zostaje w dzienniku i wróci przy
     * kolejnej okazji, jeśli akurat jest do wysłania.
     */
    public function dispatch(ShopEvent $event): bool
    {
        $id = bin2hex(random_bytes(16));
        $koperta = $event->toArray($this->shop(), $id, time());

        $this->outbox->store($id, $event->type, $koperta);

        if (!$this->isReady()) {
            return false;
        }

        $wynik = $this->send($id, $event->type, $koperta);

        // Przy okazji własnej wysyłki dobijamy to, co czeka — bez zadania
        // w harmonogramie, o którym trzeba pamiętać przy każdym wdrożeniu.
        $this->retryDue();

        return $wynik;
    }

    /** Próbne zdarzenie z panelu — sprawdza adres, podpis i odpowiedź. */
    public function sendTest(): bool
    {
        return $this->dispatch(new ShopEvent(
            type: 'quince.test',
            data: ['message' => 'Test event from Quince Events.'],
            source: 'quince_events'
        ));
    }

    /** Ponowienie zdarzeń, którym minął czas oczekiwania. */
    public function retryDue(): void
    {
        foreach ($this->outbox->due() as $zdarzenie) {
            $koperta = json_decode($zdarzenie['payload'], true);

            if (!is_array($koperta)) {
                $this->outbox->markFailed($zdarzenie['event_id'], 'Payload is not readable.');

                continue;
            }

            $this->send($zdarzenie['event_id'], (string) ($koperta['type'] ?? ''), $koperta);
        }
    }

    /**
     * Ponowienie jednego, wybranego zdarzenia od ręki — z listy albo ze
     * szczegółu w panelu, bez czekania na odstęp wyliczony po poprzedniej
     * nieudanej próbie.
     */
    public function retryNow(string $eventId): bool
    {
        $zdarzenie = $this->outbox->findByEventId($eventId);

        if ($zdarzenie === null) {
            return false;
        }

        $koperta = json_decode($zdarzenie['payload'], true);

        if (!is_array($koperta)) {
            $this->outbox->markFailed($eventId, 'Payload is not readable.');

            return false;
        }

        return $this->send($eventId, (string) ($koperta['type'] ?? ''), $koperta);
    }

    /** @param array<string,mixed> $koperta */
    private function send(string $id, string $type, array $koperta): bool
    {
        $tresc = (string) json_encode($koperta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $podpis = hash_hmac('sha256', $tresc, $this->secret());

        $ch = curl_init($this->url());

        if ($ch === false) {
            $this->outbox->markFailed($id, 'Could not open the connection.');

            return false;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $tresc,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                self::HEADER_SIGNATURE . ': sha256=' . $podpis,
                self::HEADER_EVENT . ': ' . $type,
                self::HEADER_ID . ': ' . $id,
            ],
        ]);

        $odpowiedz = curl_exec($ch);
        $kod = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $blad = curl_error($ch);
        curl_close($ch);

        if ($odpowiedz === false || $kod < 200 || $kod >= 300) {
            $this->outbox->markFailed($id, $blad !== '' ? $blad : 'Answer: HTTP ' . $kod);

            return false;
        }

        $this->outbox->markSent($id);

        return true;
    }

    /**
     * Sklep, z którego wyszło zdarzenie.
     *
     * @return array<string,mixed>
     */
    private function shop(): array
    {
        $kontekst = Context::getContext();

        return [
            'id' => (int) ($kontekst->shop->id ?? 1),
            'name' => (string) Configuration::get('PS_SHOP_NAME'),
            'url' => (string) ($kontekst->shop->getBaseURL(true) ?? ''),
            'language' => (string) ($kontekst->language->iso_code ?? ''),
        ];
    }
}
