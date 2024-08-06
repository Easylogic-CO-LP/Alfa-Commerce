<?php

/**
 * @version     CVS: 1.0.1
 * @package     com_alfa
 * @subpackage  mod_alfa
 * @author      Agamemnon Fakas <info@easylogic.gr>
 * @copyright   2024 Easylogic CO LP
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */
defined('_JEXEC') or die;

use \Joomla\CMS\Filter\InputFilter;

$safe_htmltags = array(
	'a', 'address', 'em', 'strong', 'b', 'i',
	'big', 'small', 'sub', 'sup', 'cite', 'code',
	'img', 'ul', 'ol', 'li', 'dl', 'lh', 'dt', 'dd',
	'br', 'p', 'table', 'th', 'td', 'tr', 'pre',
	'blockquote', 'nowiki', 'h1', 'h2', 'h3',
	'h4', 'h5', 'h6', 'hr');

/* @var $params Joomla\Registry\Registry */
$filter = InputFilter::getInstance($safe_htmltags);
echo $filter->clean($params->get('html_content'));
