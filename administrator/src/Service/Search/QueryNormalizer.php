<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Search;

defined('_JEXEC') or die;

/**
 * Normalises a raw keyword ONCE, outside any provider, so a SQL provider isn't
 * the one inventing "%...%" and an external engine doesn't inherit SQL assumptions.
 * Collapses whitespace and lowercases (matching
 * how the document is stored); the search column's collation does accent folding.
 *
 * @since  2.0.0
 */
class QueryNormalizer
{
    /**
     * Shortest keyword worth executing. Below this the candidate set is
     * meaningless (a single character matches most of a catalog) and the scan is
     * pure load. Returning '' lets the caller treat it as NO SEARCH rather than a
     * search that matched nothing, which is the distinction that keeps a stray
     * space or one typed letter from blanking the catalog.
     *
     * @var int
     *
     * @since  2.0.0
     */
    public const MIN_LENGTH = 2;

    /**
     * @param string $keyword The raw user keyword.
     *
     * @return string The normalised keyword, or '' when it is not worth querying.
     * @since  2.0.0
     */
    public function normalize(string $keyword): string
    {
        $keyword = PlainText::lower(PlainText::collapse($keyword));

        $length = function_exists('mb_strlen') ? mb_strlen($keyword) : \strlen($keyword);

        return $length >= self::MIN_LENGTH ? $keyword : '';
    }

    /**
     * Split a keyword into the terms every provider must match independently.
     *
     * Lives here for the same reason normalisation does: a provider that decides for
     * itself what a "word" is becomes a second definition of the query, and two
     * engines then disagree about what the customer typed while both report success.
     * Two copies of this exact expression already existed in two providers, which is
     * how such a divergence starts.
     *
     * @param string $keyword Raw or normalised keyword.
     *
     * @return string[] Terms in the order typed; empty when there is nothing to match.
     *
     * @since  2.0.0
     */
    public function tokens(string $keyword): array
    {
        $normalised = $this->normalize($keyword);

        if ($normalised === '') {
            return [];
        }

        return preg_split('/\s+/u', $normalised, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
