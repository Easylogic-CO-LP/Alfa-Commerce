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
use Alfa\Component\Alfa\Administrator\Plugin\AlfaPluginHelper;
use Alfa\Component\Alfa\Administrator\Service\Log\AlfaLog;
use Alfa\Component\Alfa\Administrator\Service\Maintenance\MaintenanceState;
use Alfa\Component\Alfa\Administrator\Service\Search\SearchEngineRegistry;
use Joomla\CMS\Association\AssociationServiceInterface;
use Joomla\CMS\Association\AssociationServiceTrait;
use Joomla\CMS\Component\Router\RouterServiceInterface;
use Joomla\CMS\Component\Router\RouterServiceTrait;
use Joomla\CMS\Extension\BootableExtensionInterface;
use Joomla\CMS\Extension\MVCComponent;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLRegistryAwareTrait;
use Psr\Container\ContainerInterface;

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

    /**
     * The request's plugin helper, set by the service provider.
     *
     * @since  2.2.0
     */
    private AlfaPluginHelper $pluginHelper;

    /**
     * The request's search engine registry, so the engines are discovered once per request (2.2.0).
     *
     * @since  2.2.0
     */
    private ?SearchEngineRegistry $searchEngines = null;

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
        // Through the plugin helper (2.2.0), one plugin at a time: a single plugin
        // whose class cannot load (missing file, stale autoload map) is logged with
        // its identity and the rest of the group imports, so it cannot take down
        // every com_alfa page, admin and storefront alike.
        $this->getPluginHelper()->importGroup('alfa');

        // Place the Alfa admin menu into Joomla's native sidebar on every com_alfa admin
        // page, registered here (once, before dispatch) so no view has to render it.
        if (Factory::getApplication()->isClient('administrator')) {
            $this->getPluginHelper()->listen('onAfterDispatch', [MenuInjector::class, 'onAfterDispatch']);
        }
    }

    /**
     * The plugin helper, com_alfa's single boundary to Joomla's plugin system (2.2.0, design 2.15.2).
     *
     * @param AlfaPluginHelper $helper The request's helper.
     *
     * @since  2.2.0
     */
    public function setPluginHelper(AlfaPluginHelper $helper): void
    {
        $this->pluginHelper = $helper;
    }

    /**
     * The plugin helper (AlfaPluginHelper::get() reads it here).
     *
     * @since  2.2.0
     */
    public function getPluginHelper(): AlfaPluginHelper
    {
        return $this->pluginHelper;
    }

    /**
     * The request's search engine registry (SearchEngineRegistry::shared() reads it here): one discovery per request.
     *
     * @since  2.2.0
     */
    public function getSearchEngineRegistry(): SearchEngineRegistry
    {
        return $this->searchEngines ??= new SearchEngineRegistry();
    }
}
