<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order;

\defined('_JEXEC') or die;

/**
 * A write refused because its external_source and external_reference already name another order
 * ({@see OrderExternalReference}): nothing was written, and the order that holds the pair is named. The REST create and
 * update answer it 409 with that order's id in meta.existing_id, so a connector that sent the same order again reads the
 * order it made the first time.
 *
 * @since  2.2.2
 */
class OrderExternalReferenceTakenException extends OrderPartRefusedException
{
    /**
     * @param int $existingId The order the pair already names.
     * @param array{source: string, reference: string} $pair The pair sent.
     * @param string $outcome What the refused write leaves (self::NOT_CREATED, self::NOT_SAVED).
     *
     * @since  2.2.2
     */
    public function __construct(public readonly int $existingId, array $pair, string $outcome = self::NOT_CREATED)
    {
        parent::__construct(
            '/external_reference',
            'order ' . $existingId . ' already carries external_source "' . $pair['source'] . '" with this external_reference,'
                . ' and one pair names one order',
            self::REFUSED,
            $outcome,
        );
    }
}
