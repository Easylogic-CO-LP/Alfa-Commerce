<?php

/**
 * @version    CVS: 1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Site\Controller;

\defined('_JEXEC') or die;

use Exception;
use Joomla\CMS\Application\SiteApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Multilanguage;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Utilities\ArrayHelper;
use Joomla\CMS\Response\JsonResponse;
use Joomla\CMS\Layout\LayoutHelper;
// use \Alfa\Component\Alfa\Site\Helper\ProductHelper;
use Alfa\Component\Alfa\Site\Helper\PriceCalculator;

/**
 * Item class.
 *
 * @since  1.6.0
 */
class ItemController extends BaseController
{
}
