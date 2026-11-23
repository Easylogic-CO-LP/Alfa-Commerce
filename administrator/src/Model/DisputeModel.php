<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Model;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Order\DisputeLedger;
use Alfa\Component\Alfa\Administrator\Service\Order\DisputeVerdict;
use Alfa\Component\Alfa\Administrator\Service\Order\DisputeWriter;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderCurrency;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Alfa\Component\Alfa\Site\Service\Pricing\PublishedDecimal;
use Closure;
use InvalidArgumentException;
use Joomla\CMS\Form\Form;
use Joomla\CMS\MVC\Model\AdminModel;
use Joomla\CMS\User\User;

/**
 * The staff acts on a card dispute: the forms the order screen's action page shows, validated from their XML, and the
 * one save of each act through the disputes ledger. The REST verbs call the same acts, so the screen and the API keep
 * one rule. The model never writes the table: the ledger does.
 *
 * The acts: record (Record a dispute), change (a record made by hand), evidence (Mark evidence sent), close (Close with
 * the outcome), reopen, same and different (the reconcile answer), amount (the amount in the order's currency).
 *
 * @since  2.2.2
 */
class DisputeModel extends AdminModel
{
    /**
     * The acts and the form each shows; the reconcile answers show none.
     *
     * @since  2.2.2
     */
    public const FORMS = [
        'record' => 'dispute',
        'change' => 'dispute',
        'evidence' => 'dispute_evidence',
        'close' => 'dispute_close',
        'reopen' => 'dispute_reopen',
        'amount' => 'dispute_amount',
    ];

    /**
     * The form of an act, with the record's values (or the payment's, for a new record), trimmed to what the record may
     * take: the close form keeps only the outcomes of its state and the amount only on a record made by hand; the
     * change form has no kind.
     *
     * @param array $data 'act' (one of self::FORMS), 'payment' (object), 'dispute' (object or null), 'typed' (what a
     *                    refused submission typed).
     * @param bool $loadData Unused: the values are the record's or the typed ones.
     *
     * @return Form|false
     *
     * @since  2.2.2
     */
    public function getForm($data = [], $loadData = false)
    {
        $act = (string) ($data['act'] ?? '');

        if (!isset(self::FORMS[$act])) {
            return false;
        }

        $form = $this->loadForm('com_alfa.dispute.' . $act, self::FORMS[$act], ['control' => 'jform', 'load_data' => false]);

        if (!$form instanceof Form) {
            return false;
        }

        $payment = $data['payment'] ?? null;
        $dispute = $data['dispute'] ?? null;

        if ($act === 'change') {
            $form->removeField('kind');
        }

        if ($act === 'close' && \is_object($dispute)) {
            $byHand = !DisputeLedger::followsProvider(record: $dispute);
            $allowed = ['won', 'lost'];

            if ((string) $dispute->state === DisputeLedger::STATE_INQUIRY) {
                $allowed[] = DisputeLedger::CLOSED_INQUIRY_ENDED;
            }

            if ($byHand) {
                $allowed[] = DisputeLedger::CLOSED_IN_ERROR;
            } else {
                $form->removeField('amount');
            }

            $this->trimOutcomes(form: $form, allowed: $allowed);
        }

        $form->bind(($data['typed'] ?? null) ?: $this->values(act: $act, payment: $payment, dispute: $dispute));

        return $form;
    }

    /**
     * Run one act: the values the act's form validated, handed to the disputes ledger as the member of staff.
     *
     * @param string $act One of self::FORMS, or 'same' or 'different'.
     * @param object $payment The disputed payment.
     * @param object|null $dispute The record; null for record.
     * @param array $values The validated values by the forms' names ('same_as' for same; 'currency', the stated
     *                      amount's ISO code, from the REST verbs only).
     * @param User $user The member of staff.
     *
     * @throws InvalidArgumentException For an act that is not one of them, or a guest.
     *
     * @since  2.2.2
     */
    public function act(string $act, object $payment, ?object $dispute, array $values, User $user): DisputeVerdict
    {
        $staff = DisputeWriter::staff(user: $user);
        $text = static fn (string $name): string => trim((string) ($values[$name] ?? ''));
        $utc = static fn (string $name): ?string => self::utc(value: $values[$name] ?? null);

        if ($act === 'record') {
            $ledger = DisputeLedger::for(paymentId: (int) $payment->id, reference: '', writer: $staff);
            $text('kind') === DisputeLedger::STATE_INQUIRY ? $ledger->inquiry() : $ledger->open();

            if ($text('reference') !== '') {
                $ledger->reference(reference: (string) $values['reference']);
            }

            return $this->facts(ledger: $ledger, payment: $payment, values: $values, text: $text, utc: $utc)->save();
        }

        if ($dispute === null) {
            throw new InvalidArgumentException('The act ' . $act . ' needs a dispute.');
        }

        $ledger = DisputeLedger::load(disputeId: (int) $dispute->id, writer: $staff);

        switch ($act) {
            case 'change':
                $ledger->reference(reference: (string) ($values['reference'] ?? ''));
                $this->facts(ledger: $ledger, payment: $payment, values: $values, text: $text, utc: $utc);
                break;

            case 'evidence':
                $ledger->evidenceSent(utc: $utc('evidence_sent_at'));
                break;

            case 'close':
                match ($text('outcome')) {
                    'won' => $ledger->won(),
                    'lost' => $ledger->lost(),
                    default => $ledger->closed(why: $text('outcome')),
                };

                if ($text('outcome') === 'lost' && $text('amount') !== '' && !DisputeLedger::followsProvider(record: $dispute)) {
                    $ledger->amount(amount: self::decimal(value: $text('amount')), currency: self::orderCode(payment: $payment));
                }

                if ($text('fee_returned') !== '') {
                    $ledger->feeReturned(amount: self::decimal(value: $text('fee_returned')), currency: (string) ($dispute->fee_currency ?? '') !== '' ? (string) $dispute->fee_currency : self::orderCode(payment: $payment));
                }

                break;

            case 'reopen':
                // Only a decided case opens again, for a new round.
                if (!\in_array((string) $dispute->state, [DisputeLedger::STATE_WON, DisputeLedger::STATE_LOST], true)) {
                    return new DisputeVerdict(
                        kind: DisputeVerdict::REFUSED,
                        code: DisputeVerdict::CODE_TRANSITION,
                        message: sprintf('Dispute #%d is %s: only a won or lost case opens again.', (int) $dispute->id, (string) $dispute->state),
                        disputeId: (int) $dispute->id,
                        existing: true,
                    );
                }

                $ledger->open();

                if ($utc('evidence_due_by') !== null) {
                    $ledger->evidenceDueBy(utc: $utc('evidence_due_by'));
                }

                break;

            case 'amount':
                $ledger->amountInOrderCurrency(amount: self::decimal(value: $text('amount')));
                break;

            case 'same':
                $ledger->sameAs(disputeId: (int) ($values['same_as'] ?? 0));
                break;

            case 'different':
                $ledger->notTheSame();
                break;

            default:
                throw new InvalidArgumentException('No dispute act ' . $act . '.');
        }

        if ($act !== 'change' && $text('note') !== '') {
            $ledger->note(text: $text('note'));
        }

        return $ledger->save();
    }

    /**
     * Joomla's AdminModel asks for a table; this model has none of its own: the ledger writes.
     *
     * @param string $name Unused.
     * @param string $prefix Unused.
     * @param array $options Unused.
     *
     * @throws InvalidArgumentException Always.
     *
     * @since  2.2.2
     */
    public function getTable($name = '', $prefix = '', $options = [])
    {
        throw new InvalidArgumentException('A dispute is written by the disputes ledger, never through a table.');
    }

    /**
     * The facts of the dispute form on the chain: amount, reason, deadline, fee, note.
     *
     * @param DisputeLedger $ledger The chain.
     * @param object $payment The payment.
     * @param array $values The values.
     * @param Closure $text A value as trimmed text.
     * @param Closure $utc A value as a UTC time or null.
     *
     * @since  2.2.2
     */
    private function facts(DisputeLedger $ledger, object $payment, array $values, Closure $text, Closure $utc): DisputeLedger
    {
        // The order's currency, unless the REST verb names the currency the bank or the provider stated.
        $ledger->amount(amount: self::decimal(value: $text('amount')), currency: $text('currency') !== '' ? $text('currency') : self::orderCode(payment: $payment))
            ->reason(code: $text('reason_code') !== '' ? $text('reason_code') : DisputeLedger::REASON_OTHER, text: $text('reason_text'))
            ->evidenceDueBy(utc: $utc('evidence_due_by'));

        if ($text('fee_amount') !== '') {
            $ledger->fee(amount: self::decimal(value: $text('fee_amount')), currency: strtoupper($text('fee_currency')) !== '' ? strtoupper($text('fee_currency')) : self::orderCode(payment: $payment));
        }

        if ($text('note') !== '') {
            $ledger->note(text: $text('note'));
        }

        return $ledger;
    }

    /**
     * The values a fresh form shows: the record's, or for a new record the payment's amount and a chargeback.
     *
     * @param string $act The act.
     * @param object|null $payment The payment.
     * @param object|null $dispute The record.
     *
     * @return array<string, mixed>
     *
     * @since  2.2.2
     */
    private function values(string $act, ?object $payment, ?object $dispute): array
    {
        if ($act === 'record' && $payment !== null) {
            $currency = OrderCurrency::of(orderId: (int) $payment->id_order);

            return ['kind' => DisputeLedger::STATE_OPEN, 'amount' => PublishedDecimal::money(money: Money::of((string) $payment->amount, $currency)->abs()), 'reason_code' => DisputeLedger::REASON_OTHER];
        }

        if ($dispute === null) {
            return [];
        }

        $values = [
            'amount' => $dispute->amount !== null ? PublishedDecimal::decimal((string) $dispute->amount) : '',
            'reason_code' => (string) $dispute->reason_code,
            'reason_text' => (string) $dispute->reason_text,
            'reference' => (string) ($dispute->reference ?? ''),
            'evidence_due_by' => (string) ($dispute->evidence_due_by ?? ''),
            'fee_amount' => $dispute->fee_amount !== null ? PublishedDecimal::decimal((string) $dispute->fee_amount) : '',
            'fee_currency' => (string) ($dispute->fee_currency ?? ''),
        ];

        return match ($act) {
            'change' => $values,
            'close' => ['amount' => $values['amount'], 'fee_returned' => $dispute->fee_returned !== null ? PublishedDecimal::decimal((string) $dispute->fee_returned) : ''],
            'reopen' => ['evidence_due_by' => ''],
            default => [],
        };
    }

    /**
     * Leave only the allowed outcomes on the close form's list.
     *
     * @param Form $form The form.
     * @param list<string> $allowed The outcomes.
     *
     * @since  2.2.2
     */
    private function trimOutcomes(Form $form, array $allowed): void
    {
        $element = $form->getFieldXml('outcome');

        if ($element === false) {
            return;
        }

        foreach (iterator_to_array($element->option, false) as $option) {
            if (!\in_array((string) $option['value'], $allowed, true)) {
                $dom = dom_import_simplexml($option);
                $dom->parentNode?->removeChild($dom);
            }
        }
    }

    /**
     * A typed decimal as the ledger takes it: a comma read as the decimal point, nothing else changed.
     *
     * @param string $value The value.
     *
     * @since  2.2.2
     */
    private static function decimal(string $value): string
    {
        return str_replace(',', '.', trim($value));
    }

    /**
     * A validated calendar value as UTC Y-m-d H:i:s, or null when empty.
     *
     * @param mixed $value The value the form's server_utc filter gave.
     *
     * @since  2.2.2
     */
    private static function utc(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : substr($value, 0, 19);
    }

    /**
     * The ISO code of the payment's order currency.
     *
     * @param object $payment The payment.
     *
     * @since  2.2.2
     */
    private static function orderCode(object $payment): string
    {
        return OrderCurrency::of(orderId: (int) $payment->id_order)->getCode();
    }
}
