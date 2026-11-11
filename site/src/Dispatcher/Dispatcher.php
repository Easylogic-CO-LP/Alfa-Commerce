<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Dispatcher;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Plugin\AlfaPluginHelper;
use Alfa\Component\Alfa\Administrator\Service\Maintenance\MaintenanceState;
use Joomla\CMS\Dispatcher\ComponentDispatcher;
use Joomla\CMS\Language\Text;
use RuntimeException;

/**
 * ComponentDispatcher class for Com_Alfa
 *
 * @since  1.0.0
 */
class Dispatcher extends ComponentDispatcher
{
    /**
     * Dispatch a controller task. Redirecting the user if appropriate.
     *
     * @return void
     *
     * @since  1.0.0
     */
    public function dispatch()
    {
        // Maintenance (Clear everything): the storefront answers that the shop is being
        // maintained, before anything reads an Alfa table.
        if (MaintenanceState::active()) {
            throw new RuntimeException(Text::_('COM_ALFA_MAINTENANCE_STOREFRONT'), 503);
        }

        // Register the item-fields plugins' getSubscribedEvents maps (their ambient
        // events: onBeforeRender, onBeforeCompileHead, ...) with the shared
        // dispatcher through the plugin helper (2.2.0), one plugin at a time, so a
        // configurator fires on every com_alfa page; its field hooks are addressed
        // to it alone and never listed there.
        AlfaPluginHelper::get()->importGroup('alfa-item-fields');

        parent::dispatch();
    }
}
