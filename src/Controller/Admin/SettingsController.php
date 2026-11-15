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

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Quince\Events\Event\EventDispatcher;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dokąd sklep wysyła zdarzenia i sprawdzenie, że połączenie działa.
 */
class SettingsController extends EventsController
{
    public function __construct(private readonly EventDispatcher $dispatcher)
    {
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to view this.')]
    public function indexAction(): Response
    {
        return $this->renderPanel(
            '@Modules/quince_events/views/templates/admin/settings.html.twig',
            [
                'enabled' => $this->dispatcher->isReady(),
                'url' => $this->dispatcher->url(),
                'hasSecret' => $this->dispatcher->secret() !== '',
            ],
            'quince_events_settings'
        );
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function saveAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_events_settings_save', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', self::DOMAIN));

            return $this->redirectToRoute('quince_events_settings');
        }

        if (!$this->dispatcher->save($request->request->all())) {
            $this->addFlash('error', $this->trans(
                'The address must start with https:// and contain a host name. Nothing was saved.',
                self::DOMAIN
            ));

            return $this->redirectToRoute('quince_events_settings');
        }

        $this->addFlash('success', $this->trans('Settings saved.', self::DOMAIN));

        return $this->redirectToRoute('quince_events_settings');
    }

    /** Nowy sekret: sprzedawca wkleja go po drugiej stronie i tam porównuje podpisy. */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function secretAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_events_settings_secret', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', self::DOMAIN));

            return $this->redirectToRoute('quince_events_settings');
        }

        $sekret = $this->dispatcher->generateSecret();

        $this->addFlash('success', $this->trans(
            'New secret: %secret%. Copy it into the receiving flow now, it will not be shown again.',
            self::DOMAIN,
            ['%secret%' => $sekret]
        ));

        return $this->redirectToRoute('quince_events_settings');
    }

    /** Próbne zdarzenie: sprawdza adres, podpis i odpowiedź odbiorcy. */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function testAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_events_settings_test', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', self::DOMAIN));

            return $this->redirectToRoute('quince_events_settings');
        }

        if (!$this->dispatcher->isReady()) {
            $this->addFlash('warning', $this->trans('Fill in the address and the secret first.', self::DOMAIN));

            return $this->redirectToRoute('quince_events_settings');
        }

        if ($this->dispatcher->sendTest()) {
            $this->addFlash('success', $this->trans('The receiving flow accepted the test event.', self::DOMAIN));
        } else {
            $this->addFlash('error', $this->trans(
                'The test event was not accepted. It waits in the queue, the reason is in the events list.',
                self::DOMAIN
            ));
        }

        return $this->redirectToRoute('quince_events_settings');
    }
}
