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

/**
 * Koperta zdarzenia wychodzącego ze sklepu.
 *
 * Jedna postać dla wszystkiego, co sklep ma do powiedzenia na zewnątrz:
 * zapytania z formularzy, zapisy do newslettera, cokolwiek dojdzie później.
 * System po drugiej stronie — n8n, Windmill, CRM — pisze wtedy jeden przepływ
 * rozpoznający rodzaj zdarzenia, zamiast osobnego pod każdy moduł.
 *
 * Rozdzielamy trzy rzeczy, bo mieszanie ich kończy się schematem, którego nikt
 * nie umie odczytać:
 *
 *   - `type`    — co się stało („contact.enquiry"),
 *   - `context` — czego dotyczyło (produkt, kategoria, zamówienie),
 *   - `data`    — co wpisał człowiek, dokładnie tak, jak wpisał.
 */
final class ShopEvent
{
    /**
     * @param string               $type    rodzaj zdarzenia, np. `contact.enquiry`
     * @param array<string,mixed>  $data    treść zgłoszenia
     * @param array<string,mixed>  $context czego zgłoszenie dotyczy
     */
    public function __construct(
        public readonly string $type,
        public readonly array $data,
        public readonly array $context = [],
        public readonly ?string $source = null
    ) {
    }

    /**
     * Postać wysyłana na zewnątrz.
     *
     * @param array<string,mixed> $shop
     *
     * @return array<string,mixed>
     */
    public function toArray(array $shop, string $id, int $time): array
    {
        return [
            'id' => $id,
            'type' => $this->type,
            'occurred_at' => gmdate('c', $time),
            'source' => $this->source,
            'shop' => $shop,
            'context' => (object) $this->context,
            'data' => (object) $this->data,
        ];
    }
}
