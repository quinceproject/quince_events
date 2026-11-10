<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Events\Controller\Admin;

use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wspólna podstawa ekranów modułu.
 *
 * Rozszerza kontroler bazowy PrestaShopa wprost, nie kontroler silnika Quince:
 * ten moduł żyje jako natywna zakładka pod „Quince project", nie wewnątrz
 * panelu Quince Engine, więc własny szablon rozszerza natywny layout panelu
 * (`@PrestaShop/Admin/layout.html.twig`), który sam rysuje sidebar z `ps_tab`.
 */
abstract class EventsController extends FrameworkBundleAdminController
{
    protected const DOMAIN = 'Modules.Quinceevents.Admin';

    /**
     * @return array<int,array{label:string,route:string}>
     */
    protected function tabs(): array
    {
        return [
            ['label' => 'Events', 'route' => 'quince_events'],
            ['label' => 'Settings', 'route' => 'quince_events_settings'],
        ];
    }

    /**
     * @param array<string,mixed> $parameters
     */
    protected function renderPanel(string $template, array $parameters, string $activeRoute): Response
    {
        $tabs = array_map(
            fn (array $tab): array => ['label' => $this->trans($tab['label'], self::DOMAIN)] + $tab,
            $this->tabs()
        );

        return $this->render($template, $parameters + [
            'quinceTabs' => $tabs,
            'quinceRoute' => $activeRoute,
        ]);
    }
}
