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
 * Jeden adres, jeden podpis, jedna postać koperty dla wszystkich modułów.
 * Po drugiej stronie stoi przepływ w n8n albo Windmillu, który rozdziela ruch
 * po rodzaju zdarzenia i robi z nim, co sklep sobie ustalił: zakłada sprawę
 * w CRM-ie, pisze na czacie zespołu, dopisuje wiersz w arkuszu.
 *
 * Trzy decyzje, które warto znać:
 *
 * **Zapis przed wysyłką.** Zdarzenie ląduje w skrzynce nadawczej, zanim
 * ruszymy z żądaniem. Przepływ po drugiej stronie bywa wyłączony, przeciążony
 * albo w trakcie wdrożenia, a zapytanie klienta nie może wtedy zniknąć.
 *
 * **Podpis, nie hasło w adresie.** Do nagłówka idzie skrót czasu wysłania
 * i treści policzony wspólnym sekretem. Odbiorca liczy go u siebie i porównuje:
 * dzięki temu wie, że koperta wyszła z tego sklepu, że nikt jej po drodze nie
 * zmienił i że nie jest starą kopią odtworzoną później. Adres
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
    public const HEADER_TIMESTAMP = 'X-Quince-Timestamp';
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

    /**
     * Zwraca `false`, gdy adres odbiorcy nie przeszedł sprawdzenia. Wtedy nic
     * nie zapisujemy, żeby nie zostawić włączonej wysyłki z dawnym adresem.
     *
     * @param array<string,mixed> $values
     */
    public function save(array $values): bool
    {
        $adres = trim((string) ($values['URL'] ?? ''));

        // Puste pole wolno zapisać: tak sprzedawca odłącza odbiorcę.
        if ($adres !== '' && !self::poprawnyAdres($adres)) {
            return false;
        }

        ConfigStore::set(self::ENABLED, empty($values['ENABLED']) ? '0' : '1');
        ConfigStore::set(self::URL, $adres);

        // Puste pole zostawia dotychczasowy sekret. Pole jest puste, bo
        // panel nie wyświetla sekretu.
        $sekret = trim((string) ($values['SECRET'] ?? ''));

        if ($sekret !== '') {
            ConfigStore::set(self::SECRET, $sekret);
        }

        return true;
    }

    /**
     * Tylko `https://` z nazwą hosta. Inne protokoły (`file://`, `gopher://`)
     * zamieniłyby pole w panelu w narzędzie do odpytywania serwera sklepu,
     * a zwykły `http://` wysyłałby dane z formularzy otwartym tekstem.
     */
    public static function poprawnyAdres(string $adres): bool
    {
        if (filter_var($adres, FILTER_VALIDATE_URL) === false || !str_starts_with(strtolower($adres), 'https://')) {
            return false;
        }

        return (string) parse_url($adres, PHP_URL_HOST) !== '';
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
        foreach ([self::ENABLED, self::URL, self::SECRET, EventOutbox::LAST_PURGE] as $klucz) {
            Configuration::deleteByName($klucz);
        }
    }

    /**
     * Zgłoszenie zdarzenia.
     *
     * Zapis do dziennika jest BEZWARUNKOWY: dzieje się zawsze, niezależnie
     * od tego, czy magistrala do zewnętrznego odbiorcy jest w ogóle włączona.
     * Dziennik ma własnych konsumentów wewnątrz sklepu (np. moduł powiadomień),
     * którym nieskonfigurowany CEP czy n8n nic nie obchodzi. `isReady()`
     * decyduje WYŁĄCZNIE o tym, czy w ogóle próbować wysyłki na zewnątrz.
     * Koperty niosą dane osobowe, więc dziennik ma termin przechowywania
     * (`EventOutbox::purgeExpired()`).
     *
     * Zwraca `true`, gdy odbiorca potwierdził przyjęcie. `false` znaczy „nie
     * teraz" (magistrala wyłączona/nieskonfigurowana, albo odbiorca nie
     * odpowiedział).
     */
    public function dispatch(ShopEvent $event): bool
    {
        $id = bin2hex(random_bytes(16));
        $koperta = $event->toArray($this->shop(), $id, time());

        $this->outbox->store($id, $event->type, $koperta);

        if (!$this->isReady()) {
            $this->outbox->purgeExpired();

            return false;
        }

        $wynik = $this->send($id, $event->type, $koperta);

        // Przy okazji własnej wysyłki dobijamy to, co czeka. Dzięki temu
        // ponowienia nie wymagają zadania w harmonogramie.
        $this->retryDue();

        return $wynik;
    }

    /** Próbne zdarzenie z panelu: sprawdza adres, podpis i odpowiedź. */
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

        $this->outbox->purgeExpired();
    }

    /**
     * Ponowienie jednego, wybranego zdarzenia od ręki (z listy albo ze
     * szczegółu w panelu), bez czekania na odstęp wyliczony po poprzedniej
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
        // Czas wysłania w podpisie: ponowienie niesie tę samą treść, więc bez niego
        // przechwyconą kopertę dałoby się odtworzyć u odbiorcy w dowolnej chwili.
        // Ten sam schemat co synchronizacja Quince CMP.
        $znacznik = time();
        $podpis = hash_hmac('sha256', $znacznik . '.' . $tresc, $this->secret());

        $adres = $this->url();
        $cel = self::sprawdzCel($adres);

        if ($cel['error'] !== '') {
            $this->outbox->markFailed($id, $cel['error']);

            return false;
        }

        $ch = curl_init($adres);

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
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            // Przekierowanie ominęłoby sprawdzenie adresu, więc za nim nie idziemy.
            CURLOPT_FOLLOWLOCATION => false,
            // Połączenie przypięte do sprawdzonego adresu. Bez tego DNS mógłby
            // między sprawdzeniem a połączeniem odpowiedzieć czym innym.
            CURLOPT_RESOLVE => $cel['resolve'],
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                self::HEADER_TIMESTAMP . ': ' . $znacznik,
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
     * Sprawdzenie adresu, pod który połączenie faktycznie pójdzie.
     *
     * Sam napis nie wystarczy: domena może wskazywać na `127.0.0.1`, `10.0.0.5`
     * albo `169.254.169.254` (usługa metadanych u dostawców chmurowych). Każdy
     * adres, na który rozwiązuje się nazwa, musi leżeć poza sieciami
     * prywatnymi i zastrzeżonymi. Ten sam sposób co w quince_cmp.
     *
     * @return array{error:string,resolve:array<int,string>}
     */
    private static function sprawdzCel(string $adres): array
    {
        $zle = static fn (string $powod): array => ['error' => $powod, 'resolve' => []];

        // Adres zapisany przed wprowadzeniem sprawdzenia przy zapisie.
        if (!self::poprawnyAdres($adres)) {
            return $zle('The address must be an absolute https:// address.');
        }

        $czesci = parse_url($adres);
        $host = (string) ($czesci['host'] ?? '');
        $port = (int) ($czesci['port'] ?? 443);
        $goly = trim($host, '[]');
        $literal = filter_var($goly, FILTER_VALIDATE_IP) !== false;
        $adresy = $literal ? [$goly] : self::rozwiaz($host);

        if ($adresy === []) {
            return $zle('The host name does not resolve to any address.');
        }

        foreach ($adresy as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return $zle('The address resolves to ' . $ip . ', which is inside this server\'s own network.');
            }
        }

        // Adres IP wpisany wprost nie przechodzi przez DNS, nie ma czego przypinać.
        if ($literal) {
            return ['error' => '', 'resolve' => []];
        }

        $ip = $adresy[0];

        return [
            'error' => '',
            'resolve' => [$host . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)],
        ];
    }

    /**
     * Wszystkie adresy IPv4 i IPv6, na które rozwiązuje się nazwa.
     *
     * @return array<int,string>
     */
    private static function rozwiaz(string $host): array
    {
        $out = [];

        foreach (@gethostbynamel($host) ?: [] as $ip) {
            $out[] = (string) $ip;
        }

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $wpis) {
            if (is_array($wpis) && isset($wpis['ipv6'])) {
                $out[] = (string) $wpis['ipv6'];
            }
        }

        return array_values(array_unique($out));
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
