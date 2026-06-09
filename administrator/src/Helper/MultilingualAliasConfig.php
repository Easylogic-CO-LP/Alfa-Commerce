<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

defined('_JEXEC') or die;

/**
 * Single source of truth for per-entity multilingual alias (URL slug) handling.
 *
 * Both consumers read from here so they can never drift:
 *   - each edit-model's save() — passes FIELDS/SCOPE for its table to
 *     MultilingualHelper::saveMultilingualData();
 *   - SyncHelper / the Tools backfill — uses the same maps to copy + de-duplicate
 *     aliases across languages.
 *
 * To make a new entity's alias translatable, add its table here.
 *
 * @since  1.0.0
 */
final class MultilingualAliasConfig
{
    /**
     * URL-slug field(s) per table — the alias fields to generate on save and
     * de-duplicate on backfill. Tables absent here have no slug field.
     *
     * @var array<string, string[]>
     *
     * @since  1.0.0
     */
    public const FIELDS = [
        '#__alfa_categories' => ['alias'],
        '#__alfa_items' => ['alias'],
        '#__alfa_manufacturers' => ['alias'],
    ];

    /**
     * Main-table column(s) scoping alias uniqueness per table. A collision only
     * counts when another row shares these values (e.g. the same parent_id).
     * Tables absent here dedupe globally ([]).
     *
     * @var array<string, string[]>
     *
     * @since  1.0.0
     */
    public const SCOPE = [
        '#__alfa_categories' => ['parent_id'],
    ];
}
