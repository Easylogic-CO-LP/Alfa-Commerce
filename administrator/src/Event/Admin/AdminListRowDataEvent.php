<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Admin;

\defined('_JEXEC') or die;

use InvalidArgumentException;

/**
 * onAlfaAdminListRowData: the current page's rows, once, before anything renders.
 *
 * READ    entity, view, screen (list), user, state, items, ids
 * WRITE   value(): [id => [key => value]], under the contributing plugin's name (the only way in)
 *
 * THIS IS THE N+1 FIREBREAK, and the reason it fires before the column phase. The whole page's
 * primary keys arrive together in `ids`, so a plugin resolves its values in exactly ONE query
 * however many rows are on screen and however many other plugins are doing the same:
 *
 *     public function onAlfaAdminListRowData(AdminListRowDataEvent $event): void
 *     {
 *         if (!$event->targets('order') || $event->getAdminContext()->ids === []) {
 *             return;
 *         }
 *
 *         $context = $event->getAdminContext();
 *         $db      = Factory::getContainer()->get(DatabaseInterface::class);
 *         $query   = $db->getQuery(true)
 *             ->select($db->quoteName(['id_order', 'band']))
 *             ->from($db->quoteName('#__plg_fraud_scores'))
 *             ->whereIn($db->quoteName('id_order'), $context->ids);
 *
 *         $event->value('alfa.fraud', 'risk', array_column($db->setQuery($query)->loadObjectList(), 'band', 'id_order'));
 *     }
 *
 * The accumulator is SHARED by every contributor: value() is its only writer, and a key belongs to
 * the first plugin that writes it (2.2.0). Contributed columns read it at render time through the
 * context's rowValues(); a column resolver that queries per row defeats the entire arrangement.
 *
 * `items` are the model's finished, enriched rows. Enriching them in place is allowed and is
 * how a plugin adds something the core row object simply lacks, but value() is the supported
 * channel; a row object's shape is the model's business and can change.
 *
 * @since  2.0.0
 */
final class AdminListRowDataEvent extends AlfaAdminEvent
{
    /**
     * @var string
     * @since  2.0.0
     */
    public const NAME = 'onAlfaAdminListRowData';

    /**
     * Contribute one value key for the page's rows (2.2.0): `$values` holds the value per row id and lands in rowData
     * as [id => [key => value]], where the plugin's columns read it. A key belongs to the first plugin that writes it;
     * another plugin writing the same key is refused with a log line, so contributors never overwrite each other.
     *
     * @param string $contributor The contributing plugin, group.element (attribution, never an authorisation).
     * @param string $key The value key, read back by the plugin's column.
     * @param array<int, mixed> $values The value per row id.
     *
     * @throws InvalidArgumentException When the contributor is not group.element.
     *
     * @since  2.2.0
     */
    public function value(string $contributor, string $key, array $values): void
    {
        $this->getAdminContext()->value($contributor, $key, $values);
    }
}
