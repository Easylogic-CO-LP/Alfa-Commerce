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

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * The catalogue as a document stream: core's default {@see DocumentSourceInterface}.
 *
 * Named for its RESPONSIBILITY, not its storage: it sources the catalogue. That it
 * currently walks MySQL is an implementation detail, and baking "Database" into the
 * name would be the same mistake as typing the contract to `Generator`.
 *
 * Its entire job is to page over items and delegate each one to
 * {@see SearchDocumentBuilder}. It deliberately knows nothing about what makes a
 * product searchable: no field lists, no combination joins, no language fallback.
 * The moment this class starts assembling documents itself, there are two
 * definitions of a document and they will drift.
 *
 * @since  1.5.0
 */
final class CatalogDocumentSource implements DocumentSourceInterface
{
    /**
     * Records fetched per keyset page. Independent of the engine's write batch size:
     * this bounds a SELECT, that bounds a payload.
     *
     * @var int
     * @since  1.5.0
     */
    private const PAGE_SIZE = 500;

    /**
     * @since  1.5.0
     */
    private readonly DatabaseInterface $db;

    /**
     * @since  1.5.0
     */
    private readonly SearchDocumentBuilder $builder;

    /**
     * @param SearchDocumentBuilder|null $builder Builds one document; defaults to a new builder.
     * @param DatabaseInterface|null $db Injected for testing; defaults to the container driver.
     *
     * @since  1.5.0
     */
    public function __construct(?SearchDocumentBuilder $builder = null, ?DatabaseInterface $db = null)
    {
        $this->db = $db ?? Factory::getContainer()->get('DatabaseDriver');
        $this->builder = $builder ?? new SearchDocumentBuilder($this->db);
    }

    /**
     * Every published content language, resolved by the language VO so the rebuild
     * path and the incremental path can never disagree about what "all languages"
     * means.
     *
     * @return LanguageTag[]
     *
     * @since  1.5.0
     */
    public function languages(): array
    {
        return LanguageTag::allContent();
    }

    /**
     * @since  1.5.0
     */
    public function countRecords(): int
    {
        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__alfa_items'));

        return (int) $this->db->setQuery($query)->loadResult();
    }

    /**
     * Stream the catalogue, one item at a time, in ascending id order.
     *
     * Pages by keyset (`id > :cursor ORDER BY id`) so the cost of reaching page N is
     * constant rather than proportional to N, and so an item inserted or deleted
     * mid-rebuild cannot shift the window and skip products.
     *
     * The generator holds one page of ids at a time; documents are built lazily per
     * item, so a consumer that stops early pays only for what it consumed.
     *
     *
     * @return iterable<int, SearchDocument[]>
     *
     * @since  1.5.0
     */
    /**
     * Re-read a named set of items, for reconciliation before activation.
     *
     * Deliberately reuses {@see self::buildFor()} rather than repeating the language
     * loop, so a record read during the walk and a record read during reconciliation
     * can never be built differently.
     *
     * @param int[] $recordIds Item ids.
     *
     * @return iterable<int, SearchDocument[]>
     *
     * @since  1.5.0
     */
    public function documentsFor(array $recordIds): iterable
    {
        $recordIds = array_values(array_unique(array_filter(array_map('intval', $recordIds))));

        if ($recordIds === []) {
            return;
        }

        sort($recordIds);

        $languages = $this->languages();

        foreach ($recordIds as $id) {
            yield $id => $this->buildFor($id, $languages);
        }
    }

    /**
     * Every language's document for one item.
     *
     * @param LanguageTag[] $languages Content languages.
     * @param int $id Item id.
     *
     * @return SearchDocument[] Empty when the item must not be indexed at all.
     *
     * @since  1.5.0
     */
    private function buildFor(int $id, array $languages): array
    {
        $documents = [];

        foreach ($languages as $language) {
            $document = $this->builder->buildDocument($id, $language->storageKey());

            // Null means "must not be indexed"; the empty array carries that upward
            // so the consumer can delete rather than merely skip.
            if ($document !== null) {
                $documents[] = $document;
            }
        }

        return $documents;
    }

    public function documents(int $afterId = 0): iterable
    {
        $languages = $this->languages();
        $cursor = max(0, $afterId);

        while (true) {
            $query = $this->db->getQuery(true)
                ->select($this->db->quoteName('id'))
                ->from($this->db->quoteName('#__alfa_items'))
                ->where($this->db->quoteName('id') . ' > :cursor')
                ->order($this->db->quoteName('id') . ' ASC')
                ->bind(':cursor', $cursor, ParameterType::INTEGER)
                ->setLimit(self::PAGE_SIZE);

            $ids = $this->db->setQuery($query)->loadColumn();

            if (!$ids) {
                return;
            }

            foreach ($ids as $id) {
                $id = (int) $id;
                $documents = [];

                foreach ($languages as $language) {
                    $document = $this->builder->buildDocument($id, $language->storageKey());

                    // Null means "must not be indexed"; the empty array carries that
                    // upward so the consumer can delete rather than merely skip.
                    if ($document !== null) {
                        $documents[] = $document;
                    }
                }

                $cursor = $id;

                yield $id => $documents;
            }
        }
    }
}
