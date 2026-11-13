<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order\Task;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryEffectOutbox;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskError;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskPolicy;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskRun;
use Alfa\Component\Alfa\Administrator\Service\Task\Tasks;
use Joomla\CMS\Factory;
use Joomla\Database\ParameterType;

/**
 * A document's stock movement that a stock plugin refused, waiting for staff (2.2.1).
 *
 * The refusal is final for the automatic tries: the drain that met it writes this task for the document's order, and
 * its first run fails with the plugin's words, so it stands in Tools > Tasks with Run again. Staff run it again once
 * the plugin allows the movement: the refused movement is opened and applied then; refused again, the new task waits
 * the same way. A movement that is no longer refused (applied, or opened again by its producer) needs nothing. One
 * task stands per document: a refusal met while one waits tells that one ({@see Tasks::failedAgain()}). What staff
 * read is a key of the language files with the document and the plugin's words ({@see self::refusalError()}).
 *
 * @since  2.2.1
 */
final class OrderDocumentStock extends OrderTaskHandler
{
    /**
     * The handler's name.
     *
     * @since  2.2.1
     */
    public const NAME = 'order.document_stock';

    /**
     * {@inheritdoc}
     *
     * @since  2.2.1
     */
    public function policy(): TaskPolicy
    {
        return new TaskPolicy(
            label: 'COM_ALFA_TASKS_HANDLER_ORDER_DOCUMENT_STOCK',
            idempotent: true,
            staffAction: TaskPolicy::ACTION_RERUN,
            attention: 'warning',
        );
    }

    /**
     * {@inheritdoc}
     *
     * @since  2.2.1
     */
    public function run(TaskRun $run): void
    {
        $documentId = (int) ($run->payload()['document'] ?? 0);
        $refusal = self::refusal(documentId: $documentId);

        if ($refusal === null) {
            return;
        }

        // Written by the drain that met the refusal: it waits for staff, and the plugin is not asked again. Its
        // policy retries nothing, so the failure stands for staff.
        if (!isset($run->payload()['rerun_of'])) {
            throw self::refused(documentId: $documentId, refusal: $refusal);
        }

        $drained = InventoryEffectOutbox::retryRefusedDocument(documentId: $documentId);

        if (isset($drained['refusals'][$documentId])) {
            throw self::refused(documentId: $documentId, refusal: (string) $drained['refusals'][$documentId]);
        }
    }

    /**
     * The failure line staff read on the task of a document's refused movement, as the task engine stores a failure of
     * the work's own: COM_ALFA_TASKS_ERROR_DOCUMENT_STOCK_REFUSED with the document and the plugin's words (one line,
     * no markup).
     *
     * @param int $documentId The document.
     * @param string $refusal The plugin's words.
     *
     * @since  2.2.1
     */
    public static function refusalError(int $documentId, string $refusal): string
    {
        return Tasks::errorKey('COM_ALFA_TASKS_ERROR_DOCUMENT_STOCK_REFUSED', $documentId, self::plain(refusal: $refusal));
    }

    /**
     * {@inheritdoc}
     *
     * @since  2.2.1
     */
    protected function what(array $payload): array
    {
        return self::text('COM_ALFA_ORDER_TASK_DOCUMENT_STOCK', (int) ($payload['document'] ?? 0));
    }

    /**
     * The refusal a document's movement stands refused with, or null when it is not refused.
     *
     * @param int $documentId The document.
     *
     * @since  2.2.1
     */
    private static function refusal(int $documentId): ?string
    {
        if ($documentId <= 0) {
            return null;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $sourceType = 'document';
        $status = InventoryEffectOutbox::STATUS_REFUSED;
        $error = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('last_error'))
                ->from($db->quoteName('#__alfa_inventory_effect_outbox'))
                ->where($db->quoteName('source_type') . ' = :sourceType')
                ->where($db->quoteName('source_id') . ' = :documentId')
                ->where($db->quoteName('status') . ' = :status')
                ->bind(':sourceType', $sourceType, ParameterType::STRING)
                ->bind(':documentId', $documentId, ParameterType::INTEGER)
                ->bind(':status', $status, ParameterType::STRING),
        )->loadResult();

        return $error === null ? null : (string) $error;
    }

    /**
     * The failure of a run that met the refusal: the task engine stores its key and arguments.
     *
     * @param int $documentId The document.
     * @param string $refusal The plugin's words.
     *
     * @since  2.2.1
     */
    private static function refused(int $documentId, string $refusal): TaskError
    {
        return new TaskError('COM_ALFA_TASKS_ERROR_DOCUMENT_STOCK_REFUSED', [$documentId, self::plain(refusal: $refusal)]);
    }

    /**
     * The plugin's words as one line with no markup.
     *
     * @param string $refusal The plugin's words.
     *
     * @since  2.2.1
     */
    private static function plain(string $refusal): string
    {
        return trim(preg_replace('/\s+/u', ' ', strip_tags($refusal)) ?? '');
    }
}
