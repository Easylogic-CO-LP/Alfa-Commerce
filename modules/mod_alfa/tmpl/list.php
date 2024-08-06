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

use Alfa\Module\Alfa\Site\Helper\AlfaHelper;

$elements = AlfaHelper::getList($params);

$tableField = explode(':', $params->get('field'));
$table_name = !empty($tableField[0]) ? $tableField[0] : '';
$field_name = !empty($tableField[1]) ? $tableField[1] : '';
?>

<?php if (!empty($elements)) : ?>
	<table class="jcc-table">
		<?php foreach ($elements as $element) : ?>
			<tr>
				<th><?php echo AlfaHelper::renderTranslatableHeader($table_name, $field_name); ?></th>
				<td><?php echo AlfaHelper::renderElement(
						$table_name, $params->get('field'), $element->{$field_name}
					); ?></td>
			</tr>
		<?php endforeach; ?>
	</table>
<?php endif;
