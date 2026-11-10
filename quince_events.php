<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Quince\\Events\\';
        if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
            return;
        }
        $path = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });
}

use Quince\Events\Event\EventDispatcher;
use Quince\Events\Event\EventOutbox;

/**
 * Quince Events — dziennik i wysyłka zdarzeń sklepu.
 *
 * Każdy inny moduł Quince (i każdy przyszły) zgłasza tu swoje zdarzenia
 * przez `Quince\Events\EventDispatcher::dispatch()`. Moduł robi dwie rzeczy
 * z każdym z nich: zapisuje je BEZWARUNKOWO w dzienniku (do przeglądania,
 * do przyszłych powiadomień sklepu — nieważne, czy jest odbiorca), i próbuje
 * wysłać je na zewnątrz, jeśli magistrala jest skonfigurowana (CEP, n8n,
 * Windmill, cokolwiek).
 *
 * Stoi obok Quince, nie w nim — osobny moduł, tak jak `quince_withdrawal`
 * czy `quince_gpsr`: to zarządzanie danymi (dziennik, filtrowanie,
 * ponawianie), nie ustawienie wyglądu, więc nie pasuje do wnętrza panelu
 * silnika. Wcześniej żył jako pozycja „System" w sidebarze Quince Engine —
 * stamtąd usunięty przy porcie do tego modułu (ta sama tabela `quince_event`,
 * ten sam adres i sekret).
 */
class Quince_Events extends Module
{
    public const DOMAIN_ADMIN = 'Modules.Quinceevents.Admin';

    public function __construct()
    {
        $this->name = 'quince_events';
        $this->tab = 'administration';
        $this->version = '0.1.1';
        $this->author = 'Quince Project';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => '9.99.99'];
        $this->dependencies = ['quince_engine'];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('Quince Events', [], self::DOMAIN_ADMIN);
        $this->description = $this->trans(
            'Shop event log and outgoing delivery to external systems (CEP, n8n, Windmill or anything else listening).',
            [],
            self::DOMAIN_ADMIN
        );
    }

    public function install(): bool
    {
        return parent::install()
            && (new EventOutbox())->installTable()
            && $this->installTabs();
    }

    public function uninstall(): bool
    {
        (new EventOutbox())->dropTable();
        (new EventDispatcher(new EventOutbox()))->removeSettings();

        return $this->uninstallTabs() && parent::uninstall();
    }

    private function installTabs(): bool
    {
        if (Tab::getIdFromClassName('AdminQuinceEvents')) {
            return true;
        }

        $idParent = (int) Tab::getIdFromClassName('AdminQuinceProject');

        $tab = new Tab();
        $tab->class_name = 'AdminQuinceEvents';
        $tab->module = $this->name;
        $tab->id_parent = $idParent > 0 ? $idParent : -1;
        $tab->route_name = 'quince_events';
        $tab->active = 1;
        $tab->icon = 'upload';
        $tab->wording = 'Events';
        $tab->wording_domain = self::DOMAIN_ADMIN;

        foreach (Language::getLanguages(false) as $lang) {
            $tab->name[$lang['id_lang']] = 'Events';
        }

        return (bool) $tab->add();
    }

    private function uninstallTabs(): bool
    {
        $idTab = (int) Tab::getIdFromClassName('AdminQuinceEvents');

        if ($idTab <= 0) {
            return true;
        }

        $tab = new Tab($idTab);

        return (bool) $tab->delete();
    }

    public function getContent(): void
    {
        Tools::redirectAdmin($this->get('router')->generate('quince_events'));
    }
}
