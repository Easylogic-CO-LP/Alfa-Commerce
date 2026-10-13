<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Search;

defined('_JEXEC') or die;

use InvalidArgumentException;

/**
 * One completion offered for a partial query: the spelling, and (when the catalogue
 * DECLARES it) what kind of thing that spelling names.
 *
 * THE LABEL IS DECLARED, NEVER INFERRED. `entity` is set only when the suggestion text
 * exactly matches a stored category or manufacturer name in the searched language:
 * the catalogue saying so, the same "ask the index, not the string" discipline as the
 * identifier guard. Statistical inference (posting-field dominance) was considered and
 * rejected: brand words live in product titles too, so dominance coin-flips on real
 * data and a wrong label reaches shoppers.
 *
 * NULL MEANS "NO CLAIM MADE": untyped. It is NOT an assertion that the word is
 * general vocabulary: an engine that cannot label at all returns null for everything,
 * and a name that matches BOTH a category and a manufacturer is ambiguous and stays
 * null. A surface may render an entity label when present; it must render nothing,
 * not "general", when absent.
 *
 * THE SUGGESTION REMAINS THE BRIDGE, NOT A QUERY REWRITE (the
 * {@see SuggestionProviderInterface} contract): the shopper still picks a spelling and
 * the real search runs with the chosen text. The label only tells them what they would
 * be searching for.
 *
 * @since  2.0.0
 */
final class SearchSuggestion
{
    /**
     * @param string $text The suggested spelling, exactly as the shopper
     *                     would search with it. Never empty.
     * @param SearchEntity|null $entity What the catalogue declares this text to name,
     *                                  or null when no claim is made.
     *
     * @throws InvalidArgumentException When the text is empty.
     *
     * @since  2.0.0
     */
    public function __construct(
        public readonly string $text,
        public readonly ?SearchEntity $entity = null,
    ) {
        if (trim($this->text) === '') {
            throw new InvalidArgumentException('A suggestion requires a non-empty text.');
        }
    }
}
