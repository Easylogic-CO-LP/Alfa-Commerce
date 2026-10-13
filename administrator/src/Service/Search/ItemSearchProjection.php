<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Search;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Projection\ProjectionInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;
use Throwable;

/**
 * Keeps the search index in step with catalogue changes, one item at a time.
 *
 * Behind the common {@see ProjectionInterface}, so the ProjectionManager runs it on
 * every item save alongside the price and filter projections.
 *
 * It builds documents and hands them to {@see SearchWriteCoordinator}; it owns NO
 * SQL of its own. Before, it wrote rows directly while the rebuild path wrote them
 * through the engine, two implementations of "index this item" that were already
 * drifting: the direct writes landed in the DEFAULT generation rather than the live
 * one, so a saved product silently stopped being re-indexed. One write path is the
 * only way that stays fixed.
 *
 * The engine keeps the hash guard, so unchanged documents (and the value/field
 * rename fan-out) still cost no writes.
 *
 * @since  2.0.0
 */
class ItemSearchProjection implements ProjectionInterface
{
    /** @var DatabaseInterface */
    private $db;

    private SearchDocumentBuilder $builder;

    private SearchWriteCoordinator $coordinator;

    /** @var LanguageTag[]|null Cached content languages. */
    private ?array $languages = null;

    /**
     * @param DatabaseInterface|null $db Injected for testing; defaults to the container driver.
     * @param SearchDocumentBuilder|null $builder Injected for testing; defaults to a new builder.
     * @param SearchWriteCoordinator|null $coordinator Injected for testing; fans writes across generations.
     *
     * @since  2.0.0
     */
    public function __construct(
        ?DatabaseInterface $db = null,
        ?SearchDocumentBuilder $builder = null,
        ?SearchWriteCoordinator $coordinator = null,
    ) {
        $this->db = $db ?? Factory::getContainer()->get('DatabaseDriver');
        $this->builder = $builder ?? new SearchDocumentBuilder($this->db);
        $this->coordinator = $coordinator ?? new SearchWriteCoordinator();
    }

    /**
     * @inheritDoc
     * @since  2.0.0
     */
    public function syncItem(int $itemId): void
    {
        if ($itemId <= 0) {
            return;
        }

        try {
            $documents = [];
            $obsolete = [];

            foreach ($this->languages() as $language) {
                $document = $this->builder->buildDocument($itemId, $language->storageKey());

                // Null means "must not be indexed" (unpublished, or nothing
                // searchable). Remove the document rather than storing a blank one,
                // so presence in the index always means findable.
                if ($document === null) {
                    $obsolete[] = DocumentId::forItem(itemId: $itemId, language: $language);

                    continue;
                }

                $documents[] = $document;
            }

            if ($documents !== []) {
                $this->report($this->coordinator->write($documents), 'syncItem(' . $itemId . ')');
            }

            if ($obsolete !== []) {
                $this->report($this->coordinator->delete($obsolete), 'syncItem(' . $itemId . ')');
            }
        } catch (Throwable $e) {
            $this->logWarning('syncItem(' . $itemId . ') failed: ' . $e->getMessage());
        }
    }

    /**
     * @inheritDoc
     * @since  2.0.0
     */
    public function syncItems(array $itemIds): void
    {
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds))));

        // No chunking here, deliberately. This was copied from a projection that
        // performs one bulk statement per chunk, where the boundary means something;
        // this one writes a document at a time, so slicing the list first is exactly
        // equivalent to walking it and only reads as though a batch size mattered.
        foreach ($itemIds as $itemId) {
            $this->syncItem((int) $itemId);
        }
    }

    /**
     * @inheritDoc
     * @since  2.0.0
     */
    public function deleteForItem(int $itemId): void
    {
        if ($itemId <= 0) {
            return;
        }

        try {
            $this->report(
                $this->coordinator->delete(DocumentId::allForItem($itemId, ...$this->languages())),
                'deleteForItem(' . $itemId . ')',
            );
        } catch (Throwable $e) {
            $this->logWarning('deleteForItem(' . $itemId . ') failed: ' . $e->getMessage());
        }
    }

    /**
     * Batch form of {@see self::deleteForItem()}, recognised by
     * {@see ProjectionManager::supportsBatchDelete()}.
     *
     * Unlike {@see self::syncItems()} (where chunking would be theatre, because a
     * document is written one at a time regardless), the coordinator's delete()
     * genuinely takes a LIST and fans one statement out per indexer. Deleting item
     * by item therefore cost one round trip per item per language; a 500-product
     * trash run meant 1000 of them on a bilingual shop. One call now carries them
     * all.
     *
     * @param int[] $itemIds The item primary keys.
     *
     *
     * @since  2.0.0
     */
    public function deleteForItems(array $itemIds): void
    {
        $itemIds = array_values(
            array_unique(
                array_filter(
                    array_map('intval', $itemIds),
                    static fn (int $itemId): bool => $itemId > 0,
                ),
            ),
        );

        if ($itemIds === []) {
            return;
        }

        $languages = $this->languages();
        $ids = [];

        foreach ($itemIds as $itemId) {
            foreach (DocumentId::allForItem($itemId, ...$languages) as $documentId) {
                $ids[] = $documentId;
            }
        }

        try {
            $this->report(
                $this->coordinator->delete($ids),
                'deleteForItems(' . \count($itemIds) . ' items)',
            );
        } catch (Throwable $e) {
            $this->logWarning('deleteForItems(' . \count($itemIds) . ' items) failed: ' . $e->getMessage());
        }
    }

    /**
     * The site's content languages, resolved once per instance.
     *
     * Delegates to the language VO, which reads `#__languages` directly. The old
     * path used LanguageHelper, which needs a running application: from CLI or cron
     * it threw, syncItem swallowed the exception, and the item was silently never
     * indexed.
     *
     * @return LanguageTag[]
     *
     * @since  2.0.0
     */
    private function languages(): array
    {
        return $this->languages ??= LanguageTag::allContent();
    }

    /**
     * Surface per-document engine failures, which the coordinator collects rather
     * than throws so that one unreachable generation cannot block a merchant's save.
     *
     * @param IndexWriteResult $result The engine's response.
     * @param string $operation Human-readable operation label.
     *
     *
     * @since  2.0.0
     */
    private function report(IndexWriteResult $result, string $operation): void
    {
        foreach ($result->failures as $key => $reason) {
            $this->logWarning($operation . ' - document "' . $key . '" rejected: ' . $reason);
        }
    }

    /**
     * Log a non-fatal warning to the com_alfa channel.
     *
     * @param string $message The message to log.
     *
     * @since  2.0.0
     */
    private function logWarning(string $message): void
    {
        Log::add('[ItemSearchProjection] ' . $message, Log::WARNING, 'com_alfa');
    }
}
