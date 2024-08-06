<?php
/**
 * @version    CVS: 1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

// No direct access
defined('_JEXEC') or die;

use \Joomla\CMS\HTML\HTMLHelper;
use \Joomla\CMS\Factory;
use \Joomla\CMS\Uri\Uri;
use \Joomla\CMS\Router\Route;
use \Joomla\CMS\Language\Text;
use \Joomla\CMS\Session\Session;
use Joomla\Utilities\ArrayHelper;


?>

<div class="item_fields">

	<table class="table">
		

		<tr>
			<th><?php echo Text::_('COM_ALFA_FORM_LBL_ITEM_NAME'); ?></th>
			<td><?php echo $this->item->name; ?></td>
		</tr>

		<tr>
			<th><?php echo Text::_('COM_ALFA_FORM_LBL_ITEM_ID'); ?></th>
			<td><?php echo $this->item->id; ?></td>
		</tr>

		<tr>
			<th><?php echo Text::_('COM_ALFA_FORM_LBL_ITEM_SHORT_DESC'); ?></th>
			<td><?php echo nl2br($this->item->short_desc); ?></td>
		</tr>

		<tr>
			<th><?php echo Text::_('COM_ALFA_FORM_LBL_ITEM_FULL_DESC'); ?></th>
			<td><?php echo nl2br($this->item->full_desc); ?></td>
		</tr>

		<tr>
			<th><?php echo Text::_('COM_ALFA_FORM_LBL_ITEM_SKU'); ?></th>
			<td><?php echo $this->item->sku; ?></td>
		</tr>

		<tr>
			<th><?php echo Text::_('COM_ALFA_FORM_LBL_ITEM_GTIN'); ?></th>
			<td><?php echo $this->item->gtin; ?></td>
		</tr>

		<tr>
			<th><?php echo Text::_('COM_ALFA_FORM_LBL_ITEM_MPN'); ?></th>
			<td><?php echo $this->item->mpn; ?></td>
		</tr>

		<tr>
			<th><?php echo Text::_('COM_ALFA_FORM_LBL_ITEM_STOCK'); ?></th>
			<td><?php echo $this->item->stock; ?></td>
		</tr>

		<tr>
			<th><?php echo Text::_('COM_ALFA_FORM_LBL_ITEM_STOCK_ACTION'); ?></th>
			<td><?php echo $this->item->stock_action; ?></td>
		</tr>

		<tr>
			<th><?php echo Text::_('COM_ALFA_FORM_LBL_ITEM_MANAGE_STOCK'); ?></th>
			<td><?php echo $this->item->manage_stock; ?></td>
		</tr>

		<tr>
			<th><?php echo Text::_('COM_ALFA_FORM_LBL_ITEM_ALIAS'); ?></th>
			<td><?php echo $this->item->alias; ?></td>
		</tr>

		<tr>
			<th><?php echo Text::_('COM_ALFA_FORM_LBL_ITEM_META_TITLE'); ?></th>
			<td><?php echo $this->item->meta_title; ?></td>
		</tr>

		<tr>
			<th><?php echo Text::_('COM_ALFA_FORM_LBL_ITEM_META_DESC'); ?></th>
			<td><?php echo nl2br($this->item->meta_desc); ?></td>
		</tr>

	</table>

</div>

