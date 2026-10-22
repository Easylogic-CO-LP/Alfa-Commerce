<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Dispatcher;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Maintenance\MaintenanceState;
use Joomla\CMS\Dispatcher\ComponentDispatcher;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\PluginHelper;
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

        // Register the item-fields plugins' getSubscribedEvents maps with the
        // GLOBAL dispatcher: bootPlugin() alone never does, so without this a
        // plugin could only answer our direct targeted calls. With it, a
        // configurator can also subscribe to ambient events (onBeforeRender,
        // onBeforeCompileHead, ...) and fire on every com_alfa page.
        PluginHelper::importPlugin('alfa-item-fields');

        parent::dispatch();
    }
}
