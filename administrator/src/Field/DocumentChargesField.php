<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\DocumentHelper;
use Alfa\Component\Alfa\Administrator\Service\Document\DocumentMoney;
use Alfa\Component\Alfa\Administrator\Service\Document\OrderChargeLines;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderCurrency;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;

/**
 * DocumentChargesField: the non-item money a document claims.
 *
 * Shipping, fees and cart-rule discounts, each a consumable balance claimed
 * once across all partial documents, so what is offered is the REMAINDER, not
 * the original amount.
 *
 * One group is rendered per source and exactly one is ever visible: the
 * ORDER's own remaining charges for a sales draft, and the CORRECTED
 * document's own for a credit; a credit gives back what that invoice
 * charged, not what the order still owes. The modal's script shows the group
 * the Corrects picker names and DISABLES the inputs of every other: a form
 * posts every enabled control, hidden or not, so hiding alone would post a
 * sales group's ticked charges with a credit. Every open row is rendered
 * enabled and every closed one disabled, so the script can tell them apart.
 *
 * A refused issue's kept draft ticks and fills the
 * one group it claimed; with no draft the render is unchanged.
 *
 * Some rows deliberately carry no checkbox. A discount that rides shipping
 * follows the shipping it reduces; on a credit, every discount follows the
 * goods. Made independently pickable, such a benefit could be left behind on
 * the draft that claimed what it reduces: permanently unclaimable, and the
 * documents would over-bill.
 *
 * @since  1.3.0
 */
class DocumentChargesField extends FormField
{
    /**
     * The field type.
     *
     * @var string
     * @since  1.3.0
     */
    protected $type = 'DocumentCharges';

    /**
     * Render the groups.
     *
     * @return string The field HTML, or nothing when the order has no claimable charges.
     *
     * @since   1.3.0
     */
    protected function getInput(): string
    {
        $orderId = (int) $this->form->getValue('id_order');

        if ($orderId <= 0) {
            return '';
        }

        $orderCharges = OrderChargeLines::remaining(orderId: $orderId);
        $hasSales = $orderCharges->shipping !== [] || $orderCharges->fees !== [] || $orderCharges->rules !== [];

        // A credit claims the corrected document's charges, so one group per
        // candidate. One batched read for all of them. An order on which a
        // credit note carries a charge line nothing active accounts for can
        // take no credit at all, so it offers no credit group.
        $correctable = OrderChargeLines::unrecordedCreditNotes(orderId: $orderId) === []
            ? DocumentHelper::correctableDocuments(DocumentHelper::getForOrder($orderId))
            : [];
        $creditCharges = OrderChargeLines::correctedChargesFor(
            correctsIds: array_map(static fn (object $d): int => (int) $d->id, $correctable),
        );

        if (!$hasSales && $correctable === []) {
            return '';
        }

        // A kept draft (a refused issue redisplayed)
        // ticks and fills the ONE group it claimed, as the operator left it:
        // the order's for any other type, the corrected document's for a
        // credit (one that named none claimed none). Every other group renders
        // exactly as a fresh open draws it.
        $kept = \is_array($this->value) ? $this->value : null;
        $keptGroup = null;

        if ($kept !== null) {
            $keptGroup = \in_array((string) $this->form->getValue('doc_type'), DocumentHelper::creditTypes(), true)
                ? ((int) $this->form->getValue('id_corrects') ?: null)
                : 0;
        }

        $html = ['<div id="issue_charges_block">'];

        if ($hasSales) {
            // A charge is open while anything of it is left at the order's own
            // scale: a minor unit at scale 8 is far below a millionth.
            $orderCurrency = OrderCurrency::of(orderId: $orderId);
            $html[] = '<div class="issue-charges-group" data-corrects="0" hidden>';
            $html[] = '<p class="small text-muted mb-1">' . Text::_('COM_ALFA_DOCUMENTS_MODAL_CHARGES_HINT') . '</p>';
            $html[] = '<table class="table table-sm align-middle"><tbody>';

            foreach ([['shipping', $orderCharges->shipping], ['fees', $orderCharges->fees]] as [$kind, $rows]) {
                foreach ($rows as $charge) {
                    $html[] = $this->chargeRow(
                        kind:   $kind,
                        id:     (int) $charge->id,
                        label:  (string) $charge->name,
                        note:   '',
                        amount: (float) $charge->remaining_incl,
                        sign:   '',
                        open:   !Money::of(abs((float) $charge->remaining_incl), $orderCurrency)->isZero(),
                        kept:   $keptGroup === 0 ? $kept : null,
                    );
                }
            }

            foreach ($orderCharges->rules as $charge) {
                $html[] = $this->chargeRow(
                    kind:     'rules',
                    id:       (int) $charge->id,
                    label:    (string) $charge->label,
                    note:     Text::_($charge->discount_class === 'shipping'
                        ? 'COM_ALFA_DOCUMENTS_MODAL_CHARGE_WITH_SHIPPING'
                        : 'COM_ALFA_DOCUMENTS_MODAL_CHARGE_DISCOUNT'),
                    amount:   (float) $charge->remaining_incl,
                    sign:     '−',
                    open:     !Money::of(abs((float) $charge->remaining_incl), $orderCurrency)->isZero(),
                    pickable: $charge->discount_class !== 'shipping',
                    kept:     $keptGroup === 0 ? $kept : null,
                );
            }

            $html[] = '</tbody></table></div>';
        }

        foreach ($correctable as $doc) {
            $group = $creditCharges[(int) $doc->id] ?? null;

            $html[] = '<div class="issue-charges-group" data-corrects="' . (int) $doc->id . '" hidden>';

            if ($group === null || ($group->shipping === [] && $group->fees === [] && $group->rules === [])) {
                $html[] = '<p class="small text-muted mb-1">'
                    . Text::_('COM_ALFA_DOCUMENTS_MODAL_CHARGES_CREDIT_NONE') . '</p></div>';

                continue;
            }

            $html[] = '<p class="small text-muted mb-1">'
                . Text::_('COM_ALFA_DOCUMENTS_MODAL_CHARGES_CREDIT_HINT') . '</p>';

            // Each shipping and fee row states what the document charged, what
            // credits already gave back and what is left, as context for the
            // operator's choice; a discount row states only what is left.
            $html[] = '<table class="table table-sm align-middle"><thead><tr>'
                . '<th class="w-1"></th>'
                . '<th>' . Text::_('COM_ALFA_DOCUMENTS_MODAL_CHARGE_COL_CHARGE') . '</th>'
                . '<th class="text-end">' . Text::_('COM_ALFA_DOCUMENTS_MODAL_CHARGE_COL_CHARGED') . '</th>'
                . '<th class="text-end">' . Text::_('COM_ALFA_DOCUMENTS_MODAL_CHARGE_COL_CREDITED') . '</th>'
                . '<th class="text-end">' . Text::_('COM_ALFA_DOCUMENTS_MODAL_CHARGE_COL_REMAINING') . '</th>'
                . '<th class="text-end">' . Text::_('COM_ALFA_DOCUMENTS_MODAL_CHARGE_COL_AMOUNT') . '</th>'
                . '</tr></thead><tbody>';

            // A credit gives back only what the operator ticks, by the amount
            // the operator states: no charge arrives ticked, and the amount is
            // the document's, at the scale it froze. The modal's script fills
            // what is left on a tick.
            $currency = DocumentMoney::currencyOf($doc);
            $money = DocumentMoney::formatter($doc);

            foreach ([['shipping', $group->shipping], ['fees', $group->fees]] as [$kind, $rows]) {
                foreach ($rows as $charge) {
                    $charged = Money::of((float) $charge->amount_incl, $currency);
                    $left = Money::of((float) $charge->remaining_incl, $currency);

                    $html[] = $this->chargeRow(
                        kind:      $kind,
                        id:        (int) $charge->id,
                        label:     (string) $charge->label,
                        note:      '',
                        amount:    (float) $charge->remaining_incl,
                        sign:      '',
                        open:      !Money::of(abs((float) $charge->remaining_incl), $currency)->isZero(),
                        ticked:    false,
                        scale:     $currency->getDecimalPlaces(),
                        remaining: number_format(
                            Money::of(abs((float) $charge->remaining_incl), $currency)->getAmount(),
                            $currency->getDecimalPlaces(),
                            '.',
                            '',
                        ),
                        kept:      $keptGroup === (int) $doc->id ? $kept : null,
                        charged:   $money($charged->getAmount()),
                        credited:  $money($charged->subtract($left)->getAmount()),
                    );
                }
            }

            foreach ($group->rules as $charge) {
                $html[] = $this->chargeRow(
                    kind:     'rules',
                    id:       (int) $charge->id,
                    label:    (string) $charge->label,
                    note:     Text::_($charge->discount_class === 'shipping'
                        ? 'COM_ALFA_DOCUMENTS_MODAL_CHARGE_WITH_SHIPPING'
                        : 'COM_ALFA_DOCUMENTS_MODAL_CHARGE_WITH_ITEMS'),
                    amount:   (float) $charge->remaining_incl,
                    sign:     '',
                    open:     !Money::of(abs((float) $charge->remaining_incl), $currency)->isZero(),
                    pickable: false,
                    scale:    $currency->getDecimalPlaces(),
                    charged:  '',
                    credited: '',
                );
            }

            $html[] = '</tbody></table></div>';
        }

        $html[] = '</div>';

        return implode('', $html);
    }

    /**
     * One charge row.
     *
     * A row whose remainder has been fully claimed is shown disabled rather
     * than dropped, so the operator can see the charge existed and was
     * already documented.
     *
     * @param string $kind Charges bucket: shipping, fees or rules.
     * @param int $id The charge's own id.
     * @param string $label Display label.
     * @param string $note Muted explanatory suffix, or empty.
     * @param float $amount The remaining amount, tax included.
     * @param string $sign Prefix for the amount, e.g. a minus for a discount.
     * @param bool $open Whether money is left on the charge (since 1.14.0): a closed
     *                   row is rendered disabled.
     * @param bool $pickable False renders no checkbox: the row rides another one.
     * @param bool $ticked Whether the row arrives ticked while money is left on it.
     * @param int $scale The decimals the remaining is shown at.
     * @param string $remaining A credit row's remaining at its document's scale, machine
     *                          formatted; set, the row carries an amount input.
     * @param array|null $kept The kept draft's charges when this row's group is the one it
     *                         claimed (since 1.14.0): the tick and the typed amount are its.
     * @param string|null $charged A credit row's charged figure (since 1.14.0); with $credited
     *                             set, the row prints both before its remaining.
     * @param string|null $credited What credits already gave back of it (since 1.14.0).
     *
     * @return string The <tr>.
     *
     * @since   1.3.0
     */
    private function chargeRow(
        string $kind,
        int $id,
        string $label,
        string $note,
        float $amount,
        string $sign,
        bool $open,
        bool $pickable = true,
        bool $ticked = true,
        int $scale = 2,
        string $remaining = '',
        ?array $kept = null,
        ?string $charged = null,
        ?string $credited = null,
    ): string {
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

        if ($kept !== null) {
            $ticked = \in_array($id, array_map('intval', (array) ($kept[$kind] ?? [])), true);
        }

        $box = $pickable
            ? '<input class="form-check-input issue-charge-pick" type="checkbox"'
                . ' name="' . $this->name . '[' . $kind . '][]" value="' . $id . '"'
                . ($open ? ($ticked ? ' checked' : '') : ' disabled') . '>'
            : '';

        // The amount a credit gives back, stated by the operator: empty until
        // the charge is ticked, never a whole credit implied by silence, or,
        // on a kept draft, what the operator typed, to be corrected in place.
        $typed = $kept !== null && \is_scalar($kept['amounts'][$kind][$id] ?? null) ? (string) $kept['amounts'][$kind][$id] : '';
        $input = $remaining !== ''
            ? '<td class="w-25"><input class="form-control form-control-sm text-end issue-charge-amount" type="text"'
                . ' inputmode="decimal" autocomplete="off"'
                . ' name="' . $this->name . '[amounts][' . $kind . '][' . $id . ']" value="' . $escape($typed) . '"'
                . ' data-remaining="' . $escape($remaining) . '"'
                . ' aria-label="' . $escape(Text::sprintf('COM_ALFA_DOCUMENTS_MODAL_CHARGE_AMOUNT', $label)) . '"'
                . ($open ? '' : ' disabled') . '></td>'
            : '';

        return '<tr><td class="w-1">' . $box . '</td>'
            . '<td>' . $escape($label)
            . ($note !== '' ? ' <span class="small text-muted">' . $note . '</span>' : '')
            . '</td>'
            . ($charged !== null && $credited !== null
                ? '<td class="text-end">' . $charged . '</td><td class="text-end">' . $credited . '</td>'
                : '')
            . '<td class="text-end">' . $sign . number_format($amount, $scale, ',', '.') . '</td>'
            . $input . '</tr>';
    }

    /**
     * No label row: the block carries its own heading.
     *
     * @return string Empty.
     *
     * @since   1.3.0
     */
    protected function getLabel(): string
    {
        return '';
    }
}
