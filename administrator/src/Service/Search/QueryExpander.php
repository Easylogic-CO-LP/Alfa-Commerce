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

use Joomla\CMS\Filter\OutputFilter;

/**
 * THE CROSS-MODE QUERY-EXPANSION RULE: what other spellings a search term is
 * allowed to mean, everywhere, identically.
 *
 * Transliteration is ONE-WAY (native script to Latin), so cross-script search
 * needs two mechanisms, and this class is the single authority both consult:
 *
 *  - QUERY EXPANSION ({@see self::expand()}) covers "native-script query finds
 *    Latin text": each term becomes (original OR transliterated), OR within the
 *    variants, AND across terms. EXPANSION, NEVER REPLACEMENT: replacing the
 *    typed form would make the same query mean different things per mode, which
 *    is the divergence the search architecture exists to prevent. Every mode (
 *    the database search, the Indexed engine, the Enterprise engine) consumes
 *    this rule identically; an engine may expand FURTHER (synonyms, typo
 *    variants), it may never redefine what core produced.
 *
 *  - STORED COPIES ({@see self::transliterateText()}) cover the reverse, "Latin
 *    query finds native-script prose": no stored alias column exists for
 *    descriptions and no reverse transliteration exists, so index-side writers
 *    (the Indexed projection, the Enterprise analyzer) store the transliterated
 *    spelling BESIDE the original at index time, through the same primitive the
 *    query side expands with. One primitive on both sides is what makes a
 *    stored copy provably matchable by an expanded query.
 *
 * THE PRIMITIVE IS PER-WORD, ON BOTH SIDES, BY CONSTRUCTION. The alias
 * machinery this reuses ({@see OutputFilter::stringURLSafe}, the same call that
 * generates every alias in the catalogue) folds a WHOLE string into one
 * hyphen-joined slug: fed a sentence, it would weld the words together and no
 * per-word query could ever match again. Feeding it one word at a time keeps
 * word boundaries where the query tokeniser puts them, and makes punctuated
 * tokens fold identically on both sides ("l'oréal" becomes "loreal" in the
 * stored copy and in the expanded query, never "l oreal" on one side only).
 *
 * Transliteration quality follows the CONTENT language's installed
 * transliterator, exactly as alias generation always has: Joomla's language
 * pack rules where present, the ICU fallback otherwise. Identical input under
 * an identical installation folds identically; that is the whole guarantee the
 * index/query pair needs.
 *
 * @since  2.0.0
 */
final class QueryExpander
{
    /**
     * One term's Latin fold, through the same machinery that generates aliases.
     *
     * @param string $term One term: a single word, never a phrase.
     * @param LanguageTag $language The content language whose transliteration
     *                              rules apply.
     *
     * @return string Lowercase Latin form ('' when nothing usable survives;
     *                the caller decides whether that drops the variant or the
     *                word).
     *
     * @since  2.0.0
     */
    public function transliterate(string $term, LanguageTag $language): string
    {
        if (trim($term) === '') {
            return '';
        }

        return OutputFilter::stringURLSafe($term, $language->displayTag());
    }

    /**
     * The spellings ONE query term is allowed to match: the original always
     * first, its transliterated form OR-ed in when it adds anything.
     *
     * The variant joins only when it differs from the original and is long
     * enough to be worth matching: a one-character fold matches most of a
     * catalogue and is pure noise, the same floor {@see QueryNormalizer}
     * applies to the query itself.
     *
     * @param string $term One core-normalised term.
     * @param LanguageTag $language The content language of the query.
     *
     * @return string[] Variant group, original first; never empty.
     *
     * @since  2.0.0
     */
    public function expand(string $term, LanguageTag $language): array
    {
        $variants = [$term];
        $latin = $this->transliterate($term, $language);

        if (
            $latin !== ''
            && $latin !== $term
            && (\function_exists('mb_strlen') ? mb_strlen($latin) : \strlen($latin)) >= QueryNormalizer::MIN_LENGTH
        ) {
            $variants[] = $latin;
        }

        return $variants;
    }

    /**
     * The index-side counterpart of {@see self::expand()}: the transliterated
     * copy of a prepared text, word by word.
     *
     * Only words whose fold DIFFERS are kept: the original text already sits
     * in the same index, so re-storing a word that folds to itself would only
     * fatten the haystack. For native-script prose this yields the full
     * transliterated copy; for Latin prose it yields nothing, which is exactly
     * the storage cost the feature deserves.
     *
     * @param string $text Prepared plain text (already stripped and
     *                     collapsed by the document builder).
     * @param LanguageTag $language The content language of the text.
     *
     * @return string Space-joined transliterated words, '' when nothing differs.
     *
     * @since  2.0.0
     */
    public function transliterateText(string $text, LanguageTag $language): string
    {
        $text = PlainText::collapse($text);

        if ($text === '') {
            return '';
        }

        $copies = [];

        foreach (preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $latin = $this->transliterate($word, $language);

            // The SAME floor {@see self::expand()} applies on the query side: a
            // stored fold below MIN_LENGTH is a copy no query token could target
            // (a one-character keyword never survives normalisation), so keeping
            // it only bloats the haystack and (with the sibling analyzer gating
            // identically) would make one mode match a sub-2-char fold the others
            // drop. One floor, all three emitters.
            if (
                $latin !== ''
                && $latin !== PlainText::lower($word)
                && (\function_exists('mb_strlen') ? mb_strlen($latin) : \strlen($latin)) >= QueryNormalizer::MIN_LENGTH
            ) {
                $copies[] = $latin;
            }
        }

        return implode(' ', $copies);
    }
}
