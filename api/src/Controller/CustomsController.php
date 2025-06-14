<?php
/**
 * @version    CVS: 1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */
namespace Alfa\Component\Alfa\Api\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\ApiController;

/**
 * The Customs controller
 *
 * @since  1.0.1
 */
class CustomsController extends ApiController 
{
	/**
	 * The content type of the item.
	 *
	 * @var    string
	 * @since  1.0.1
	 */
	protected $contentType = 'customs';

	/**
	 * The default view for the display method.
	 *
	 * @var    string
	 * @since  1.0.1
	 */
	protected $default_view = 'customs';
}
