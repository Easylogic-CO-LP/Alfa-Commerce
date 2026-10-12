<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Choice field: item-page selector, buttons mode. Same markup as radio with a
 * button skin (variant-options--buttons).
 *
 * @var array $displayData field, values, inputName, selected, available, inStock
 */

defined('_JEXEC') or die;

$displayData['variant'] = 'buttons';

require __DIR__ . '/item-radio.php';
