<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Extension;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Menu\MenuInjector;
use Alfa\Component\Alfa\Administrator\Service\Log\AlfaLog;
use Alfa\Component\Alfa\Administrator\Service\Maintenance\MaintenanceState;
use Joomla\CMS\Association\AssociationServiceInterface;
use Joomla\CMS\Association\AssociationServiceTrait;
use Joomla\CMS\Component\Router\RouterServiceInterface;
use Joomla\CMS\Component\Router\RouterServiceTrait;
use Joomla\CMS\Extension\BootableExtensionInterface;
use Joomla\CMS\Extension\MVCComponent;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLRegistryAwareTrait;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\PluginHelper;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Component class for Alfa
 *
 * @since  1.0.0
 */
class AlfaComponent extends MVCComponent implements RouterServiceInterface, BootableExtensionInterface, AssociationServiceInterface
{
    use AssociationServiceTrait;
    use RouterServiceTrait;
    use HTMLRegistryAwareTrait;

    /**
     * The archived condition
     *
     * @since  1.0.0
     */
    public const CONDITION_ARCHIVED = 2;

    /**
     * The published condition
     *
     * @since  1.0.0
     */
    public const CONDITION_PUBLISHED = 1;

    /**
     * The unpublished condition
     *
     * @since  1.0.0
     */
    public const CONDITION_UNPUBLISHED = 0;

    /**
     * The trashed condition
     *
     * @since  1.0.0
     */
    public const CONDITION_TRASHED = -2;

    /** @inheritdoc
     * @since  1.0.0
     */
    public function boot(ContainerInterface $container)
    {
        // Every log category this component writes to, registered once, before
        // anything can log. An unregistered Joomla category is SILENTLY
        // dropped, so this is what puts the audit trail on disk at all; see
        // AlfaLog for the two signature traps the map is shaped around.
        // Registration is an array write and creates no file until a line
        // actually fires, so this costs nothing on a request that logs nothing.
        AlfaLog::register();

        // Maintenance (Clear everything): no feature plugin is loaded and no menu is placed,
        // since both read the Alfa tables. The component still boots, so its router and its
        // Clear everything page work.
        if (MaintenanceState::active()) {
            return;
        }

        // Load the `alfa` FEATURE plugin group (subscriptions, bundle,
        // documents, ...) whenever the component boots: feature plugins
        // subscribe to the shop's lifecycle events (onOrderStatusChange,
        // onItemPrepareForm, onCollectStockMovements, ...) without being
        // site-wide system plugins. An empty group is a no-op, so the
        // core stands alone with zero feature plugins installed.
        //
        // Guarded: import instantiates every enabled plugin of the group, so a
        // single plugin whose class cannot load (missing file, stale autoload
        // map) would otherwise take down every com_alfa page, admin and
        // storefront alike. Healthy plugins still boot individually at their
        // dispatch sites.
        try {
            PluginHelper::importPlugin('alfa');
        } catch (Throwable $e) {
            Log::add('AlfaComponent: feature plugin import failed: ' . $e->getMessage(), Log::WARNING, 'com_alfa.panel');
        }

        // Place the Alfa admin menu into Joomla's native sidebar on every com_alfa admin
        // page, registered here (once, before dispatch) so no view has to render it.
        $app = Factory::getApplication();

        if ($app->isClient('administrator')) {
            $app->getDispatcher()->addListener('onAfterDispatch', [MenuInjector::class, 'onAfterDispatch']);
        }
    }
}
