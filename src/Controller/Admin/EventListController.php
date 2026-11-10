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

use Quince\Events\Event\EventOutbox;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dziennik zdarzeń: filtr po typie, statusie i dacie, stronicowanie.
 */
class EventListController extends EventsController
{
    public function __construct(private readonly EventOutbox $outbox)
    {
    }

    public function indexAction(Request $request): Response
    {
        $filters = [
            'type' => (string) $request->query->get('type', ''),
            'status' => (string) $request->query->get('status', ''),
            'from' => (string) $request->query->get('from', ''),
            'to' => (string) $request->query->get('to', ''),
        ];

        $total = $this->outbox->countSearch($filters);
        $pages = max(1, (int) ceil($total / EventOutbox::PER_PAGE));
        $page = min($pages, max(1, (int) $request->query->get('page', 1)));

        return $this->renderPanel(
            '@Modules/quince_events/views/templates/admin/events.html.twig',
            [
                'counts' => $this->outbox->counts(),
                'events' => $this->outbox->search($filters, $page),
                'filters' => $filters,
                'page' => $page,
                'pages' => $pages,
                'total' => $total,
            ],
            'quince_events'
        );
    }
}
