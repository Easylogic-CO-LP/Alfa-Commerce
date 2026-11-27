<?php

/**
 * The page a record's form goes back to on Close and Save & Close: the request's return value (the base64 of an
 * internal address, as Joomla's form controller reads it), carried through the POST as a hidden field. Empty when the
 * form was opened from its list, so the controller goes back to the list. Every record form renders it beside its
 * form token; a form opened with no return value prints an empty field and nothing changes.
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * @since  2.3.0
 */

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\RequestScalar;
use Joomla\CMS\Factory;

$return = (string) RequestScalar::read(Factory::getApplication()->getInput(), 'return', '', 'base64');
?>
<input type="hidden" name="return" value="<?php echo htmlspecialchars($return, ENT_QUOTES, 'UTF-8'); ?>">
