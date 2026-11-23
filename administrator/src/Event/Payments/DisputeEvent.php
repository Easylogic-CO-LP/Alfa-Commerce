<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Payments;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Order\DisputeVerdict;
use Joomla\CMS\Event\AbstractImmutableEvent;

/**
 * What core asks the gateway of a disputed payment, addressed to that one plugin on its method of the event's name: the
 * dispute it is about, and the gateway's answer.
 *
 * Input: getDispute() (the record: id, id_order, id_payment, reference, state, stage, amount, stated_amount,
 * stated_currency, evidence_due_by), getOrderId(), getPaymentId(), getReference().
 * Output: exactly one of setVerdict($verdict), the verdict of the plugin's own $this->dispute(...)->save() once the
 * provider answered, or setError($sentence), the plugin's own words for staff when the provider could not be asked or
 * refused. A handler that answers neither leaves staff told that the provider gave no answer.
 *
 * @since  2.3.0
 */
abstract class DisputeEvent extends AbstractImmutableEvent
{
    /**
     * The verdict the plugin's write answered.
     *
     * @since  2.3.0
     */
    private ?DisputeVerdict $verdict = null;

    /**
     * The plugin's sentence for staff.
     *
     * @since  2.3.0
     */
    private string $error = '';

    /**
     * The dispute's record as core holds it.
     *
     * @api READ ONLY: the record (id, id_order, id_payment, reference, state, stage, amount, stated_amount, stated_currency, evidence_due_by).
     *
     * @since  2.3.0
     */
    public function getDispute(): object
    {
        return (object) $this->arguments['dispute'];
    }

    /**
     * The order of the disputed payment.
     *
     * @since  2.3.0
     */
    public function getOrderId(): int
    {
        return (int) $this->getDispute()->id_order;
    }

    /**
     * The disputed payment.
     *
     * @since  2.3.0
     */
    public function getPaymentId(): int
    {
        return (int) $this->getDispute()->id_payment;
    }

    /**
     * The provider's dispute id the record carries; '' when none is known.
     *
     * @since  2.3.0
     */
    public function getReference(): string
    {
        return (string) ($this->getDispute()->reference ?? '');
    }

    /**
     * The plugin's answer: the verdict of its own dispute() write.
     *
     * @api HOOK: the answer of a handler that applied the provider's word.
     *
     * @param DisputeVerdict $verdict The verdict.
     *
     * @since  2.3.0
     */
    public function setVerdict(DisputeVerdict $verdict): void
    {
        $this->verdict = $verdict;
        $this->error = '';
    }

    /**
     * The plugin's answer: why the provider could not be asked, or what it refused, in its own words for staff.
     *
     * @api HOOK: the answer of a handler that could not apply the provider's word.
     *
     * @param string $sentence The sentence, plain text.
     *
     * @since  2.3.0
     */
    public function setError(string $sentence): void
    {
        $this->error = $sentence;
        $this->verdict = null;
    }

    /**
     * The verdict the plugin set, or null.
     *
     * @since  2.3.0
     */
    public function getVerdict(): ?DisputeVerdict
    {
        return $this->verdict;
    }

    /**
     * The sentence the plugin set, or ''.
     *
     * @since  2.3.0
     */
    public function getError(): string
    {
        return $this->error;
    }
}
