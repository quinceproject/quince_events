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

use Db;

/**
 * Skrzynka nadawcza zdarzeń.
 *
 * Zdarzenie **najpierw ląduje w bazie**, dopiero potem próbujemy je wysłać.
 * Odwrotna kolejność wygląda prościej i gubi zgłoszenia: gdy przepływ po drugiej
 * stronie akurat nie odpowiada, zapytanie klienta znika bez śladu, a sklep nie
 * ma nawet czego ponowić.
 *
 * Ponowienia idą przy okazji kolejnych wysyłek, z rosnącym odstępem — bez
 * własnego zadania w harmonogramie, bo to kolejna rzecz do skonfigurowania
 * w sklepie, o której nikt nie pamięta. Operator może też ponowić jedno
 * zdarzenie od ręki, z listy albo ze szczegółu — bez czekania na odstęp.
 */
final class EventOutbox
{
    public const TABLE = 'quince_event';

    /** Ile razy próbujemy, zanim uznamy zdarzenie za nie do dostarczenia. */
    public const MAX_ATTEMPTS = 6;

    /** Ile zdarzeń pokazuje jedna strona listy w panelu. */
    public const PER_PAGE = 20;

    /** Odstępy między próbami w sekundach: minuta, pięć, pół godziny, dwie, sześć. */
    private const BACKOFF = [60, 300, 1800, 7200, 21600];

    /** Ile zdarzeń ponawiamy przy jednej okazji — żeby nie opóźniać żądania klienta. */
    private const RETRY_BATCH = 3;

    public function installTable(): bool
    {
        return (bool) Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE . '` (
                `id_quince_event` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `event_id` VARCHAR(40) NOT NULL,
                `type` VARCHAR(64) NOT NULL,
                `payload` MEDIUMTEXT NOT NULL,
                `status` ENUM("pending","sent","failed") NOT NULL DEFAULT "pending",
                `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `last_error` VARCHAR(255) NOT NULL DEFAULT "",
                `next_try_at` INT(11) UNSIGNED NOT NULL DEFAULT 0,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_quince_event`),
                UNIQUE KEY `uniq_event` (`event_id`),
                INDEX `idx_do_wyslania` (`status`, `next_try_at`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4'
        );
    }

    public function dropTable(): bool
    {
        return (bool) Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . self::TABLE . '`');
    }

    /** @param array<string,mixed> $payload */
    public function store(string $eventId, string $type, array $payload): bool
    {
        return Db::getInstance()->insert(self::TABLE, [
            'event_id' => pSQL($eventId),
            'type' => pSQL(substr($type, 0, 64)),
            'payload' => pSQL((string) json_encode($payload), true),
            'status' => 'pending',
            'attempts' => 0,
            'next_try_at' => 0,
            'date_add' => date('Y-m-d H:i:s'),
            'date_upd' => date('Y-m-d H:i:s'),
        ]);
    }

    public function markSent(string $eventId): void
    {
        Db::getInstance()->update(self::TABLE, [
            'status' => 'sent',
            'last_error' => '',
            'date_upd' => date('Y-m-d H:i:s'),
        ], 'event_id = "' . pSQL($eventId) . '"');
    }

    /**
     * Nieudana próba: rosnący odstęp do następnej, a po wyczerpaniu prób
     * zdarzenie zostaje w bazie ze stanem „failed". Nie kasujemy go — to jest
     * ślad po zapytaniu klienta i ktoś może chcieć je odczytać ręcznie.
     */
    public function markFailed(string $eventId, string $error): void
    {
        $wiersz = Db::getInstance()->getRow(
            'SELECT attempts FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE event_id = "' . pSQL($eventId) . '"'
        );

        $proby = 1 + (int) ($wiersz['attempts'] ?? 0);
        $odstep = self::BACKOFF[min($proby - 1, count(self::BACKOFF) - 1)];

        Db::getInstance()->update(self::TABLE, [
            'status' => $proby >= self::MAX_ATTEMPTS ? 'failed' : 'pending',
            'attempts' => $proby,
            'last_error' => pSQL(substr($error, 0, 255)),
            'next_try_at' => time() + $odstep,
            'date_upd' => date('Y-m-d H:i:s'),
        ], 'event_id = "' . pSQL($eventId) . '"');
    }

    /**
     * Zdarzenia czekające na ponowienie.
     *
     * @return array<int,array{event_id:string,payload:string}>
     */
    public function due(): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT event_id, payload FROM `' . _DB_PREFIX_ . self::TABLE . '`
             WHERE status = "pending" AND attempts > 0 AND next_try_at <= ' . time() . '
             ORDER BY next_try_at ASC
             LIMIT ' . self::RETRY_BATCH
        ) ?: [];

        $out = [];

        foreach ($wiersze as $wiersz) {
            $out[] = [
                'event_id' => (string) $wiersz['event_id'],
                'payload' => (string) $wiersz['payload'],
            ];
        }

        return $out;
    }

    /** @return array{pending:int,sent:int,failed:int} */
    public function counts(): array
    {
        $out = ['pending' => 0, 'sent' => 0, 'failed' => 0];

        $wiersze = Db::getInstance()->executeS(
            'SELECT status, COUNT(*) AS ile FROM `' . _DB_PREFIX_ . self::TABLE . '` GROUP BY status'
        ) ?: [];

        foreach ($wiersze as $wiersz) {
            $out[(string) $wiersz['status']] = (int) $wiersz['ile'];
        }

        return $out;
    }

    /** Jedno zdarzenie po kluczu głównym, do strony szczegółu w panelu. */
    public function get(int $id): ?array
    {
        $wiersz = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE id_quince_event = ' . $id
        );

        return $wiersz !== false && $wiersz !== null ? $wiersz : null;
    }

    /** Jedno zdarzenie po jego identyfikatorze zewnętrznym, do ponowienia od ręki. */
    public function findByEventId(string $eventId): ?array
    {
        $wiersz = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE event_id = "' . pSQL($eventId) . '"'
        );

        return $wiersz !== false && $wiersz !== null ? $wiersz : null;
    }

    /**
     * Lista do panelu, z filtrami i stronicowaniem.
     *
     * @param array{type?:string,status?:string,from?:string,to?:string} $filters
     *
     * @return array<int,array<string,mixed>>
     */
    public function search(array $filters, int $page): array
    {
        $offset = (max(1, $page) - 1) * self::PER_PAGE;

        return Db::getInstance()->executeS(
            'SELECT event_id, id_quince_event, type, status, attempts, last_error, date_add
             FROM `' . _DB_PREFIX_ . self::TABLE . '`
             WHERE ' . $this->whereClause($filters) . '
             ORDER BY id_quince_event DESC
             LIMIT ' . self::PER_PAGE . ' OFFSET ' . $offset
        ) ?: [];
    }

    /**
     * @param array{type?:string,status?:string,from?:string,to?:string} $filters
     */
    public function countSearch(array $filters): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE ' . $this->whereClause($filters)
        );
    }

    /**
     * @param array{type?:string,status?:string,from?:string,to?:string} $filters
     */
    private function whereClause(array $filters): string
    {
        $warunki = ['1'];

        $typ = trim((string) ($filters['type'] ?? ''));
        if ($typ !== '') {
            $warunki[] = 'type = "' . pSQL($typ) . '"';
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if (in_array($status, ['pending', 'sent', 'failed'], true)) {
            $warunki[] = 'status = "' . pSQL($status) . '"';
        }

        $od = trim((string) ($filters['from'] ?? ''));
        if ($od !== '') {
            $warunki[] = 'date_add >= "' . pSQL($od) . ' 00:00:00"';
        }

        $do = trim((string) ($filters['to'] ?? ''));
        if ($do !== '') {
            $warunki[] = 'date_add <= "' . pSQL($do) . ' 23:59:59"';
        }

        return implode(' AND ', $warunki);
    }
}
