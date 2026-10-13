<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Search;

defined('_JEXEC') or die;

/**
 * The item's descriptive prose, kept as SEPARATE fields rather than one merged blob.
 *
 * INVARIANT PROTECTED: the canonical document never pre-flattens. Concatenating the
 * three into a single string here would be irreversible: no consumer could tell a
 * summary from a specification sheet from robot-facing metadata, and no engine could
 * weight them apart. One engine wants `short_description` and `description` as
 * distinct analysed fields with distinct boosts; another wants them as distinct
 * searchable attributes; only the SQL LIKE projection wants them welded, and that is
 * a decision that engine makes for itself.
 *
 * The three differ in kind, not merely in length:
 *  - short  - the merchant's own summary, the highest-signal prose on the record
 *  - full   - specifications and marketing copy; large, and the first thing to drown
 *             a relevance-free matcher
 *  - meta   - written for search ENGINES, routinely duplicated across a catalogue
 *
 * PLAIN TEXT ONLY. Values arrive already extracted and capped by the builder (see
 * {@see PlainText}), so no consumer has to strip markup, and no two consumers can
 * disagree about how.
 *
 * DELIBERATELY NO DEFAULT-LANGUAGE FALLBACK, unlike title and alias. A missing title
 * makes a product unfindable by the name the storefront renders, which is a defect;
 * an untranslated description is simply absent, and borrowing another language's
 * prose would put foreign-language text into a language-scoped document.
 *
 * @since  2.0.0
 */
final class SearchDescriptions
{
    /**
     * @param string $short The merchant's summary, plain text.
     * @param string $full The long description, plain text, capped at build time.
     * @param string $meta Meta title and meta description, plain text.
     *
     * @since  2.0.0
     */
    public function __construct(
        public readonly string $short = '',
        public readonly string $full = '',
        public readonly string $meta = '',
    ) {
    }

    /**
     * Whether there is any prose at all.
     *
     *
     * @since  2.0.0
     */
    public function isEmpty(): bool
    {
        return trim($this->short) === '' && trim($this->full) === '' && trim($this->meta) === '';
    }
}
