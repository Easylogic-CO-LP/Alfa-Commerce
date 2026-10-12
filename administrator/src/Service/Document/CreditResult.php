<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Document;

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/**
 * What the credit calculator answers: one result every entrance reads.
 *
 * A screen chooses its words from the outcome and prints the figures it
 * carries; it computes none of them. The operator text is not
 * escaped here: every screen escapes it.
 *
 * @since  1.14.0
 */
final class CreditResult
{
    /**
     * @param CreditOutcome $outcome What the credit comes to.
     * @param string $reasonKey The operator sentence's language key; '' for Issuable, and for an
     *                          Invalid whose refusal carries none.
     * @param array $reasonValues Its values in placeholder order; money already formatted at the
     *                            corrected document's scale.
     * @param string $detail The technical text (logs; shown escaped when there is no key).
     * @param object|null $corrected The corrected `#__alfa_documents` row; null only when it does not exist.
     * @param string $language The credit's language; '' when not resolved.
     * @param array $draft The complete draft DocumentIssuer::issue() receives; [] when not built.
     * @param object|null $preview The DocumentIssuer::preview() answer; null when not run.
     * @param array $record The charge decision record; [] when not built.
     * @param array $exclusions The record entries the one list of charges left out names.
     * @param string $fingerprint The availability fingerprint; '' unless the outcome is issuable.
     *
     * @since   1.14.0
     */
    public function __construct(
        public readonly CreditOutcome $outcome,
        public readonly string $reasonKey,
        public readonly array $reasonValues,
        public readonly string $detail,
        public readonly ?object $corrected,
        public readonly string $language,
        public readonly array $draft,
        public readonly ?object $preview,
        public readonly array $record,
        public readonly array $exclusions,
        public readonly string $fingerprint,
    ) {
    }

    /**
     * Is this the credit that was previewed under the given fingerprint?
     *
     * @param string $fingerprint The fingerprint the preview handed out.
     *
     * @return bool False whenever this result has no fingerprint.
     *
     * @since   1.14.0
     */
    public function matches(string $fingerprint): bool
    {
        return $this->fingerprint !== '' && hash_equals($this->fingerprint, $fingerprint);
    }

    /**
     * The operator's sentence, translated; the technical detail when there is no key. Not escaped.
     *
     *
     * @since   1.14.0
     */
    public function operatorText(): string
    {
        return $this->reasonKey !== '' ? Text::sprintf($this->reasonKey, ...$this->reasonValues) : $this->detail;
    }
}
