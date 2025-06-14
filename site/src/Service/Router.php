<?php

/**
 * @version    CVS: 1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Site\Service;

// No direct access
defined('_JEXEC') or die;

use Joomla\CMS\Component\Router\RouterViewConfiguration;
use Joomla\CMS\Component\Router\RouterView;
use Joomla\CMS\Component\Router\Rules\StandardRules;
use Joomla\CMS\Component\Router\Rules\NomenuRules;
use Joomla\CMS\Component\Router\Rules\MenuRules;
use Joomla\CMS\Factory;
use Joomla\CMS\Categories\Categories;
use Joomla\CMS\Application\SiteApplication;
use Joomla\CMS\Categories\CategoryFactoryInterface;
use Joomla\CMS\Categories\CategoryInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\CMS\Menu\AbstractMenu;
use Joomla\CMS\Component\ComponentHelper;

/**
 * Class AlfaRouter
 *
 */
class Router extends RouterView
{
    private $noIDs;
    /**
     * The category factory
     *
     * @var    CategoryFactoryInterface
     *
     * @since  1.0.1
     */
    private $categoryFactory;

    /**
     * The category cache
     *
     * @var    array
     *
     * @since  1.0.1
     */
    private $categoryCache = [];

    public function __construct(SiteApplication $app, AbstractMenu $menu, CategoryFactoryInterface $categoryFactory, DatabaseInterface $db)
    {
        $params = ComponentHelper::getParams('com_alfa');
        $this->noIDs = (bool)$params->get('sef_ids');
        $this->categoryFactory = $categoryFactory;


        $manufacturers = new RouterViewConfiguration('manufacturers');
        $this->registerView($manufacturers);
        $ccManufacturer = new RouterViewConfiguration('manufacturer');
        $ccManufacturer->setKey('id')->setParent($manufacturers);
        $categories = new RouterViewConfiguration('categories');
        $this->registerView($categories);
        $items = new RouterViewConfiguration('items');
        $this->registerView($items);
        $ccItem = new RouterViewConfiguration('item');
        $ccItem->setKey('id')->setParent($items);
        $this->registerView($ccItem);

        $cart = new RouterViewConfiguration('cart');
        $this->registerView($cart);

        $empties = new RouterViewConfiguration('empties');
        $this->registerView($empties);

        parent::__construct($app, $menu);

        $this->attachRule(new MenuRules($this));
        $this->attachRule(new StandardRules($this));
        $this->attachRule(new NomenuRules($this));
    }

    public function parse(&$segments)
    {
        exit;
    }


    /**
     * Method to get the segment(s) for an item
     *
     * @param string $id ID of the item to retrieve the segments for
     * @param array $query The request that is built right now
     *
     * @return  array|string  The segments of this item
     */
    public function getItemSegment($id, $query)
    {
        if (!strpos($id, ':')) {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $dbquery = $db->getQuery(true);
            $dbquery->select($dbquery->qn('alias'))
                ->from($dbquery->qn('#__alfa_items'))
                ->where('id = ' . $dbquery->q($id));
            $db->setQuery($dbquery);

            $id .= ':' . $db->loadResult();
        }

        if ($this->noIDs) {
            list($void, $segment) = explode(':', $id, 2);

            return [$void => $segment];
        }
        return [(int)$id => $id];
    }


    /**
     * Method to get the segment(s) for an item
     *
     * @param string $segment Segment of the item to retrieve the ID for
     * @param array $query The request that is parsed right now
     *
     * @return  mixed   The id of this item or false
     */
    public function getItemId($segment, $query)
    {
        if ($this->noIDs) {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $dbquery = $db->getQuery(true);
            $dbquery->select($dbquery->qn('id'))
                ->from($dbquery->qn('#__alfa_items'))
                ->where('alias = ' . $dbquery->q($segment));
            $db->setQuery($dbquery);

            return (int)$db->loadResult();
        }
        return (int)$segment;
    }

    /**
     * Method to get categories from cache
     *
     * @param array $options The options for retrieving categories
     *
     * @return  CategoryInterface  The object containing categories
     *
     * @since   1.0.1
     */
    private function getCategories(array $options = []): CategoryInterface
    {
        $key = serialize($options);

        if (!isset($this->categoryCache[$key])) {
            $this->categoryCache[$key] = $this->categoryFactory->createCategory($options);
        }

        return $this->categoryCache[$key];
    }


    /**
     * Method to get the segment(s) for a manufacturer
     *
     * @param string $id ID of the manufacturer to retrieve the segments for
     * @param array $query The request that is built right now
     *
     * @return  array|string  The segments of this manufacturer
     */
    public function getManufacturerSegment($id, $query)
    {
        if (!strpos($id, ':')) {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $dbquery = $db->getQuery(true);
            $dbquery->select($dbquery->qn('alias'))
                ->from($dbquery->qn('#__alfa_manufacturers'))
                ->where('id = ' . $dbquery->q($id));
            $db->setQuery($dbquery);

            $id .= ':' . $db->loadResult();
        }

        if ($this->noIDs) {
            list($void, $segment) = explode(':', $id, 2);

            return [$void => $segment];
        }
        return [(int)$id => $id];
    }

    /**
     * Method to get the ID for a manufacturer
     *
     * @param string $segment Segment of the manufacturer to retrieve the ID for
     * @param array $query The request that is parsed right now
     *
     * @return  mixed   The id of this manufacturer or false
     */
    public function getManufacturerId($segment, $query)
    {
        if ($this->noIDs) {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $dbquery = $db->getQuery(true);
            $dbquery->select($dbquery->qn('id'))
                ->from($dbquery->qn('#__alfa_manufacturers'))
                ->where('alias = ' . $dbquery->q($segment));
            $db->setQuery($dbquery);

            return (int)$db->loadResult();
        }
        return (int)$segment;
    }
}
