<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\DocumentHelper;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$wa = $this->getDocument()->getWebAssetManager();
$wa->useScript('keepalive')
    ->useScript('form.validate')
    ->useScript('com_alfa.validate.engine')
    ->useStyle('com_alfa.validate');

$item = $this->item;

// The semantic columns rendered read-only below: the registry owns them
// (installer / package providers); the model strips them from every POST.
$semantics = [
    'COM_ALFA_DOCUMENTTYPE_SEM_ALIAS' => '<code>' . $this->escape((string) $item->alias) . '</code>',
    'COM_ALFA_DOCUMENTTYPE_HEADING_FAMILY' => Text::_('COM_ALFA_DOCTYPE_FAMILY_' . strtoupper((string) $item->family)),
    'COM_ALFA_DOCUMENTTYPE_HEADING_POOL' => Text::_('COM_ALFA_DOCTYPE_POOL_' . strtoupper((string) $item->quantity_pool)),
    'COM_ALFA_DOCUMENTTYPE_SEM_SHOWS_PRICES' => Text::_((int) $item->shows_prices === 1 ? 'JYES' : 'JNO'),
    'COM_ALFA_DOCUMENTTYPE_SEM_MOVEMENT_MODE' => Text::_('COM_ALFA_DOCUMENTTYPE_MOVEMENT_' . strtoupper((string) $item->movement_mode)),
    'COM_ALFA_DOCUMENTTYPE_SEM_REQUIRES_ORIGINAL' => Text::_('COM_ALFA_DOCUMENTTYPE_REQORIG_' . strtoupper((string) $item->requires_original)),
    'COM_ALFA_DOCUMENTTYPE_SEM_REQUIRES_TAX_ID' => Text::_((int) $item->requires_buyer_tax_id === 1 ? 'JYES' : 'JNO'),
    'COM_ALFA_DOCUMENTTYPE_HEADING_ORIGIN' => Text::_((int) $item->is_core === 1 ? 'COM_ALFA_DOCUMENTTYPE_BADGE_CORE' : 'COM_ALFA_DOCUMENTTYPE_BADGE_PACKAGE'),
];
?>

<form action="<?php echo Route::_('index.php?option=com_alfa&layout=edit&id=' . (int) $item->id); ?>"
      method="post" enctype="multipart/form-data" name="adminForm" id="documenttype-form"
      class="form-validate form-horizontal">

    <div class="row">
        <div class="col-lg-6">
            <?php echo $this->form->renderField('name'); ?>
            <?php echo $this->form->renderField('icon'); ?>
            <?php echo $this->form->renderField('color'); ?>
            <?php // Only a credit type corrects other types. A type a provider
                  // registered corrects what its provider declares: shown, not
                  // edited; only a type of the merchant's own is set here. ?>
            <?php if ((string) $item->family === DocumentHelper::FAMILY_CREDIT) : ?>
                <?php if (DocumentHelper::pairingIsDeclared(row: $item)) : ?>
                    <?php
                    $corrected = implode(', ', array_map(
                        static fn (string $alias): string => DocumentHelper::typeLabel($alias),
                        (array) $item->corrects,
                    ));
                    $provider = (int) $item->is_core === 1 ? Text::_('COM_ALFA_DOCUMENTTYPE_BADGE_CORE') : (string) $item->provider;
                    ?>
                    <div class="control-group" data-alfa-corrects-declared>
                        <div class="control-label"><?php echo Text::_('COM_ALFA_DOCUMENTTYPE_FIELD_CORRECTS'); ?></div>
                        <div class="controls">
                            <p class="mb-1"><?php echo $this->escape($corrected !== '' ? $corrected : Text::_('COM_ALFA_DOCUMENTTYPE_CORRECTS_NONE')); ?></p>
                            <p class="small text-muted mb-0"><?php echo Text::sprintf('COM_ALFA_DOCUMENTTYPE_CORRECTS_DECLARED', $this->escape($provider)); ?></p>
                        </div>
                    </div>
                <?php else : ?>
                    <?php echo $this->form->renderField('corrects'); ?>
                <?php endif; ?>
            <?php endif; ?>
            <?php echo $this->form->renderField('ordering'); ?>
            <?php echo $this->form->renderField('state'); ?>
        </div>
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header">
                    <span class="icon-lock" aria-hidden="true"></span>
                    <?php echo Text::_('COM_ALFA_DOCUMENTTYPE_SEMANTICS_HEADING'); ?>
                </div>
                <div class="card-body">
                    <p class="small text-muted mb-3"><?php echo Text::_('COM_ALFA_DOCUMENTTYPE_SEMANTICS_HINT'); ?></p>
                    <table class="table table-sm mb-0">
                        <tbody>
                        <?php foreach ($semantics as $label => $value) : ?>
                            <tr>
                                <td class="w-40 text-muted"><?php echo Text::_($label); ?></td>
                                <td><?php echo $value; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <input type="hidden" name="task" value=""/>
    <?php echo $this->form->getInput('id'); ?>
    <?php echo HTMLHelper::_('form.token'); ?>
</form>
