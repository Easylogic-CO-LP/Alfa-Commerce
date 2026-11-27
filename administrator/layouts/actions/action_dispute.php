<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * The form of a dispute act on the action page of the disputed payment: the act's form from its XML (DisputeModel), its
 * fields rendered by Joomla, posted to the act's _do arm with the record's id. Accept at the provider shows the typed
 * confirmation instead. No markup is built in JavaScript: the action page posts the named controls of the
 * data-action-form block.
 *
 * @var array $displayData
 *   act      string                         the act (record, change, evidence, close, reopen, amount, accept)
 *   dispute  object|null                    the record; null for record
 *   payment  object                         the disputed payment
 *   form     \Joomla\CMS\Form\Form|null     the act's form; null for accept
 *   submit   string                         the _do arm
 *   refusal  string                         why a submission was refused, plain text; '' for a fresh form
 *   phrase   string                         accept only: what to type to confirm
 *
 * @since  2.3.0
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;

$act = (string) ($displayData['act'] ?? '');
$dispute = $displayData['dispute'] ?? null;
$payment = $displayData['payment'] ?? null;
$form = $displayData['form'] ?? null;
$refusal = (string) ($displayData['refusal'] ?? '');
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$post = [\Alfa\Component\Alfa\Administrator\Helper\ActionRegistry::DISPUTE_FIELD => (int) ($dispute->id ?? 0)];
$intro = $act === 'record'
    ? Text::sprintf('COM_ALFA_DISPUTE_FORM_INTRO_RECORD', (int) ($payment->id ?? 0))
    : Text::sprintf('COM_ALFA_DISPUTE_FORM_INTRO_' . strtoupper($act), (int) ($dispute->id ?? 0));
?>
<div class="dispute-form" data-action-form>
    <p class="mb-3"><?php echo $escape($intro); ?></p>

    <?php if ($refusal !== '') : ?>
        <div class="alert alert-danger" role="alert"><?php echo $escape($refusal); ?></div>
    <?php endif; ?>

    <?php if ($act === 'accept') : ?>
        <?php echo LayoutHelper::render('actions.typedconfirm', ['phrase' => (string) ($displayData['phrase'] ?? ''), 'id' => 'dispute-accept-phrase'], JPATH_ADMINISTRATOR . '/components/com_alfa/layouts'); ?>
    <?php elseif ($form !== null) : ?>
        <?php foreach ($form->getFieldset('dispute') as $field) : ?>
            <?php echo $field->renderField(); ?>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="d-flex justify-content-end">
        <button type="button"
                class="btn <?php echo $act === 'accept' ? 'btn-danger' : 'btn-primary'; ?>"
                data-action-run="<?php echo $escape((string) ($displayData['submit'] ?? '')); ?>"
                data-action-data="<?php echo $escape((string) json_encode($post)); ?>">
            <?php echo Text::_($act === 'accept' ? 'COM_ALFA_DISPUTE_ACTION_ACCEPT' : 'COM_ALFA_DISPUTE_FORM_SAVE'); ?>
        </button>
    </div>
</div>
