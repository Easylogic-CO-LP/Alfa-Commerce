<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * The product page's sections, under the gallery and the buy box: the collection PageSections builds for the
 * product (the description, the specifications table, the shop's and the product's own sections, the fields
 * placed in a section of their own, the plugins' sections), drawn by common/sections.php in the mode the menu item
 * chose. Nothing when the product has no section, or when no mode is given.
 *
 * Overridable as html/layouts/com_alfa/item/sections.php; the presentations themselves as common/sections.php,
 * common/tabs.php and common/accordion.php.
 *
 * @var array $displayData ['item' => object, 'mode' => string (stacked, tabs, accordion, or '' for nothing)]
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Helper\Layout;
use Alfa\Component\Alfa\Site\Helper\PageSections;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Language\Text;

$item = $displayData['item'] ?? null;
$mode = (string) ($displayData['mode'] ?? '');

if (!\is_object($item) || $mode === '') {
    return;
}

$sections = PageSections::collect($item, ComponentHelper::getParams('com_alfa'));

if ($sections === []) {
    return;
}

echo Layout::render('common.sections', [
    'sections' => $sections,
    'mode' => $mode,
    'label' => Text::_('COM_ALFA_ITEM_SECTIONS_NAV_LABEL'),
]);
