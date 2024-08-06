<?php
/**
 * @version    CVS: 1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Api\View\Items;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\View\JsonApiView as BaseApiView;

/**
 * The Items view
 *
 * @since  1.0.1
 */
class JsonApiView extends BaseApiView
{
	/**
	 * The fields to render item in the documents
	 *
	 * @var    array
	 * @since  1.0.1
	 */
	protected $fieldsToRenderItem = [
		'state', 
		'ordering', 
		'name', 
		'id', 
		'sku', 
		'stock', 
	];

	/**
	 * The fields to render items in the documents
	 *
	 * @var    array
	 * @since  1.0.1
	 */
	protected $fieldsToRenderList = [
		'state', 
		'ordering', 
		'name', 
		'id', 
		'sku', 
		'stock', 
	];
}