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

$element = AlfaHelper::getItem($params);
?>

<?php if (!empty($element)) : ?>
	<div>
		<?php $fields = get_object_vars($element); ?>
		<?php foreach ($fields as $field_name => $field_value) : ?>
			<?php if (AlfaHelper::shouldAppear($field_name)): ?>
				<div class="row">
					<div class="span4">
						<strong><?php echo AlfaHelper::renderTranslatableHeader($params->get('item_table'), $field_name); ?></strong>
					</div>
					<div
						class="span8"><?php echo AlfaHelper::renderElement($params->get('item_table'), $field_name, $field_value); ?></div>
				</div>
			<?php endif; ?>
		<?php endforeach; ?>
	</div>
<?php endif;
