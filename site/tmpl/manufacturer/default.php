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

<div class="manufacturer_fields">

    <table class="table">


        <tr>
            <th><?php echo Text::_('COM_ALFA_FORM_LBL_MANUFACTURER_NAME'); ?></th>
            <td><?php echo $this->manufacturer->name; ?></td>
        </tr>

        <tr>
            <th><?php echo Text::_('COM_ALFA_FORM_LBL_MANUFACTURER_ID'); ?></th>
            <td><?php echo $this->manufacturer->id; ?></td>
        </tr>

        <tr>
            <th><?php echo Text::_('COM_ALFA_FORM_LBL_MANUFACTURER_SHORT_DESC'); ?></th>
            <td><?php echo nl2br($this->manufacturer->short_desc); ?></td>
        </tr>

        <tr>
            <th><?php echo Text::_('COM_ALFA_FORM_LBL_MANUFACTURER_FULL_DESC'); ?></th>
            <td><?php echo nl2br($this->manufacturer->full_desc); ?></td>
        </tr>

        <tr>
            <th><?php echo Text::_('COM_ALFA_FORM_LBL_MANUFACTURER_SKU'); ?></th>
            <td><?php echo $this->manufacturer->sku; ?></td>
        </tr>

        <tr>
            <th><?php echo Text::_('COM_ALFA_FORM_LBL_MANUFACTURER_GTIN'); ?></th>
            <td><?php echo $this->manufacturer->gtin; ?></td>
        </tr>

        <tr>
            <th><?php echo Text::_('COM_ALFA_FORM_LBL_MANUFACTURER_MPN'); ?></th>
            <td><?php echo $this->manufacturer->mpn; ?></td>
        </tr>

        <tr>
            <th><?php echo Text::_('COM_ALFA_FORM_LBL_MANUFACTURER_STOCK'); ?></th>
            <td><?php echo $this->manufacturer->stock; ?></td>
        </tr>

        <tr>
            <th><?php echo Text::_('COM_ALFA_FORM_LBL_MANUFACTURER_STOCK_ACTION'); ?></th>
            <td><?php echo $this->manufacturer->stock_action; ?></td>
        </tr>

        <tr>
            <th><?php echo Text::_('COM_ALFA_FORM_LBL_MANUFACTURER_MANAGE_STOCK'); ?></th>
            <td><?php echo $this->manufacturer->manage_stock; ?></td>
        </tr>

        <tr>
            <th><?php echo Text::_('COM_ALFA_FORM_LBL_MANUFACTURER_ALIAS'); ?></th>
            <td><?php echo $this->manufacturer->alias; ?></td>
        </tr>

        <tr>
            <th><?php echo Text::_('COM_ALFA_FORM_LBL_MANUFACTURER_META_TITLE'); ?></th>
            <td><?php echo $this->manufacturer->meta_title; ?></td>
        </tr>

        <tr>
            <th><?php echo Text::_('COM_ALFA_FORM_LBL_MANUFACTURER_META_DESC'); ?></th>
            <td><?php echo nl2br($this->manufacturer->meta_desc); ?></td>
        </tr>

    </table>

</div>

