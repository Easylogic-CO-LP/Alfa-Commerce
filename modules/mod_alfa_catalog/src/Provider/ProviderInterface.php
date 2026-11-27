<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Module\AlfaCatalog\Site\Provider;

use Joomla\Registry\Registry;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * What a tab shows, read for one kind of record: products, categories or manufacturers.
 *
 * A provider reads through the component's models and services only, never a table of its own, and always within a
 * bound: the tab's number, itself capped. It pays a fixed number of queries per tab, never one per record. A tab
 * creates only the provider of its own kind.
 *
 * @since  2.3.0
 */
interface ProviderInterface
{
    /**
     * A tab's query from its stored row: only what this kind reads, each value checked against what it accepts.
     * The query is all a provider is handed later, and with the module it names the tab's cache entry.
     *
     * @param array $row The stored tab row.
     * @param string $display The tab's presentation: grid, slider or list.
     *
     * @return array<string, mixed> Scalars and lists of scalars only.
     *
     * @since  2.3.0
     */
    public function query(array $row, string $display): array;

    /**
     * The records of a tab, in display order and within its bound.
     *
     * @param array<string, mixed> $query The tab's query (query()).
     * @param Registry $params The module's settings.
     *
     * @return list<object>
     *
     * @since  2.3.0
     */
    public function records(array $query, Registry $params): array;

    /**
     * What a stored result depends on beside the module and the query, or null when the result is one visitor's own
     * and is never stored.
     *
     * @param array<string, mixed> $query The tab's query (query()).
     * @param Registry $params The module's settings.
     *
     * @return array<int, mixed>|null
     *
     * @since  2.3.0
     */
    public function varies(array $query, Registry $params): ?array;
}
