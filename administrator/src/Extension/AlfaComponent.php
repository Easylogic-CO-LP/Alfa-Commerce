<?php

/**
 * @version    1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP and Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Association\AssociationServiceTrait;
use Joomla\CMS\Categories\CategoryServiceInterface;
use Joomla\CMS\Categories\CategoryServiceTrait;
use Joomla\CMS\Component\Router\RouterServiceInterface;
use Joomla\CMS\Component\Router\RouterServiceTrait;
use Joomla\CMS\Extension\BootableExtensionInterface;
use Joomla\CMS\Extension\MVCComponent;
use Joomla\CMS\HTML\HTMLRegistryAwareTrait;
use Joomla\CMS\Tag\TagServiceTrait;
use Psr\Container\ContainerInterface;
use stdClass;

/**
 * Component class for Alfa
 *
 * @since  1.0.1
 */
class AlfaComponent extends MVCComponent implements RouterServiceInterface, BootableExtensionInterface, CategoryServiceInterface
{
    use AssociationServiceTrait;
    use RouterServiceTrait;
    use HTMLRegistryAwareTrait;
    use CategoryServiceTrait, TagServiceTrait {
        CategoryServiceTrait::getTableNameForSection insteadof TagServiceTrait;
        CategoryServiceTrait::getStateColumnForSection insteadof TagServiceTrait;
    }

    /**
     * The archived condition
     *
     * @since   4.0.0
     */
    public const CONDITION_ARCHIVED = 2;

    /**
     * The published condition
     *
     * @since   4.0.0
     */
    public const CONDITION_PUBLISHED = 1;

    /**
     * The unpublished condition
     *
     * @since   4.0.0
     */
    public const CONDITION_UNPUBLISHED = 0;

    /**
     * The trashed condition
     *
     * @since   4.0.0
     */
    public const CONDITION_TRASHED = -2;

    /** @inheritdoc  */
    public function boot(ContainerInterface $container)
    {
        /**
         * Load the constant early as it is used in class files before the class itself is loaded.
         * @deprecated 4.4.0 will be removed in 7.0
         */
        //        \defined('JPATH_PLATFORM') or \define('JPATH_PLATFORM', __DIR__);
        //		$db = $container->get('DatabaseDriver');
        //		$this->getRegistry()->register('alfa', new ALFA($db));
    }

    /**
     * Returns the table for the count items functions for the given section.
         *
         * @param   string    The section
         *
         *
         * @since   4.0.0
         */
    protected function getTableNameForSection(?string $section = null): ?string
    {
        return '#__alfa';
    }

    /**
     * Adds Count Items for Category Manager.
     *
     * @param stdClass[] $items The category objects
     * @param string $section The section
     *
     * @return void
     *
     * @since   4.0.0
     */
    public function countItems(array $items, string $section)
    {
    }
}
