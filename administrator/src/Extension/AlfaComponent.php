<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Association\AssociationServiceInterface;
use Joomla\CMS\Association\AssociationServiceTrait;
use Joomla\CMS\Component\Router\RouterServiceInterface;
use Joomla\CMS\Component\Router\RouterServiceTrait;
use Joomla\CMS\Extension\BootableExtensionInterface;
use Joomla\CMS\Extension\MVCComponent;
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

    /** @inheritdoc
     * @since  1.0.0
     */
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
}
