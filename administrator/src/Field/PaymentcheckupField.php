<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Payments\PaymentCheckupEvent;
use Alfa\Component\Alfa\Administrator\Helper\PaymentCheckupHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;

/**
 * The checkup of a payment method, drawn inside its own settings: the setup
 * steps its gateway needs, each with where it stands, and a "Check
 * connection" button that asks the provider whether the saved settings work.
 *
 * Every gateway places it the same way: one card per credential set, each the
 * first field of its own section, right after that section's note, and never
 * with a showon. Both cards are always shown; each judges only its own set,
 * and the card of the set new payments use carries an "In use" badge:
 *
 *   <field name="section_sandbox" type="note" .../>
 *   <field name="checkup_sandbox" type="paymentcheckup" variant="sandbox"/>
 *   ...
 *   <field name="section_live" type="note" .../>
 *   <field name="checkup_live" type="paymentcheckup" variant="live"/>
 *
 * A method without credential sets (manual payments) places it once with
 * variant="general".
 *
 * The steps come from the gateway (onPaymentCheckup, see PaymentCheckupEvent);
 * this field only finds the saved method and draws the card.
 *
 * @since  1.10.0
 */
final class PaymentcheckupField extends FormField
{
    /**
     * @var string
     *
     * @since  1.10.0
     */
    protected $type = 'Paymentcheckup';

    /**
     * The card spans the form; its title is drawn inside it.
     *
     * @var bool
     *
     * @since  1.10.0
     */
    protected $hiddenLabel = true;

    /**
     * @return string The checkup card.
     *
     * @since  1.10.0
     */
    protected function getInput(): string
    {
        $variant = (string) ($this->element['variant'] ?? '');
        $variant = \in_array($variant, PaymentCheckupEvent::VARIANTS, true) ? $variant : 'general';

        Factory::getApplication()->getDocument()->getWebAssetManager()
            ->registerAndUseScript('com_alfa.payment-checkup', 'media/com_alfa/js/admin/payment-checkup.js', [], ['defer' => true]);

        return PaymentCheckupHelper::render(
            methodId: (int) $this->form->getValue('id'),
            variant: $variant,
            check: false,
            domId: (string) $this->id . '-card',
            title: Text::_('COM_ALFA_CHECKUP_TITLE_' . strtoupper($variant)),
        );
    }

    /**
     * @return string Nothing: the title sits inside the card.
     *
     * @since  1.10.0
     */
    protected function getLabel(): string
    {
        return '';
    }
}
