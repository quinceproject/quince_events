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

use Quince\Events\Event\EventDispatcher;
use Quince\Events\Event\EventOutbox;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Szczegół jednego zdarzenia: treść koperty, ostatni błąd, ponowienie od ręki.
 */
class EventDetailController extends EventsController
{
    public function __construct(
        private readonly EventOutbox $outbox,
        private readonly EventDispatcher $dispatcher
    ) {
    }

    public function indexAction(int $id): Response
    {
        $event = $this->outbox->get($id);

        if ($event === null) {
            $this->addFlash('error', $this->trans('That event does not exist.', self::DOMAIN));

            return $this->redirectToRoute('quince_events');
        }

        $koperta = json_decode((string) $event['payload'], true);

        return $this->renderPanel(
            '@Modules/quince_events/views/templates/admin/event.html.twig',
            [
                'event' => $event,
                'payloadJson' => json_encode(
                    is_array($koperta) ? $koperta : [],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
            ],
            'quince_events'
        );
    }

    public function retryAction(int $id, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_events_event_retry', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', self::DOMAIN));

            return $this->redirectToRoute('quince_events_event', ['id' => $id]);
        }

        $event = $this->outbox->get($id);

        if ($event === null) {
            $this->addFlash('error', $this->trans('That event does not exist.', self::DOMAIN));

            return $this->redirectToRoute('quince_events');
        }

        if ($this->dispatcher->retryNow((string) $event['event_id'])) {
            $this->addFlash('success', $this->trans('The receiving flow accepted the event.', self::DOMAIN));
        } else {
            $this->addFlash('error', $this->trans(
                'The event was not accepted. It stays in the queue — the reason is below.',
                self::DOMAIN
            ));
        }

        return $this->redirectToRoute('quince_events_event', ['id' => $id]);
    }
}
