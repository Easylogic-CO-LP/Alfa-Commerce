<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\View;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Api\Helper\ApiInclude;

/**
 * A translated resource's list rows with their translations, on request (2.2.2).
 *
 * Every read of a translated resource carries its texts flat, in the language the request asks (lang) or the shop's
 * default content language. With include=translations a list row, as a single record, also carries `translations`:
 * every content language's texts, keyed by language (en_gb, el_gr), the object a write takes. Read for the page's
 * rows in one statement per language (MultilingualHelper::getMultilingualDataForIds()), never one per row. Without
 * it no read carries translations (the single record's model reads them only when asked, ApiInclude).
 *
 * A view that uses this names its translations' table and key in {@see self::translationSource()}.
 *
 * @since  2.2.2
 */
trait ListsTranslations
{
    /**
     * The translations of the resource: its base table, the key of its language tables, and the texts a row carries
     * ([] for every text the language tables hold).
     *
     * @return array{0: string, 1: string, 2: string[]}
     *
     * @since  2.2.2
     */
    abstract protected function translationSource(): array;

    /**
     * Display a list, each row with its translations when the request asks for them (include=translations).
     *
     * @param array|null $items The rows, or null for the model's.
     *
     * @return string
     *
     * @since  2.2.2
     */
    public function displayList(?array $items = null)
    {
        if ($items !== null || !ApiInclude::asks(ApiInclude::TRANSLATIONS)) {
            return parent::displayList($items);
        }

        [$table, $key, $texts] = $this->translationSource();
        $rows = $this->getModel()->getItems() ?: [];
        $translations = MultilingualHelper::getMultilingualDataForIds(
            ids: array_map(static fn (object $row): int => (int) ($row->id ?? 0), $rows),
            primaryColumnName: $key,
            tableName: $table,
        );
        $items = [];

        foreach ($rows as $row) {
            $byLanguage = $translations[(int) ($row->id ?? 0)] ?? [];
            $row->translations = $texts === []
                ? $byLanguage
                : array_map(static fn (array $one): array => array_intersect_key($one, array_flip($texts)), $byLanguage);
            $items[] = $this->prepareItem($row);
        }

        if (!\in_array('translations', $this->fieldsToRenderList, true)) {
            $this->fieldsToRenderList[] = 'translations';
        }

        return parent::displayList($items);
    }
}
