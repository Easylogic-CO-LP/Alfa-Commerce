<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Cart;

use Alfa\Component\Alfa\Administrator\Helper\FieldsHelper;

defined('_JEXEC') or die;

/**
 * Where an opened cart is delivered: the one binding its goods and its carriage are priced at.
 *
 * The place this request bound (the checkout form's, the submission's, the order builder's), else the place field of
 * the cart's delivery address. The computation binds it and reads it for the shipping methods, their quotes and the
 * shipping taxes; the hydrator reads it to price the lines; one object, made by the opener, holds it for both, so the
 * goods and the carriage of one cart are never priced at two places.
 *
 * @since  2.2.1
 */
final class DeliveryBinding
{
    /** @var int|null The place this request bound, or null. @since 2.2.1 */
    private ?int $bound = null;

    /**
     * Bind the place this request says the order is going to.
     *
     * @param int|null $placeId The place, or null (or 0) for none.
     *
     * @return bool Whether the bound place changed.
     *
     * @since  2.2.1
     */
    public function bind(?int $placeId): bool
    {
        $placeId = $placeId !== null && $placeId > 0 ? $placeId : null;

        if ($placeId === $this->bound) {
            return false;
        }

        $this->bound = $placeId;

        return true;
    }

    /**
     * The place this request bound, or null.
     *
     * @since  2.2.1
     */
    public function bound(): ?int
    {
        return $this->bound;
    }

    /**
     * The delivery place: the one bound, else the place field of the cart's delivery address.
     *
     * @param object|null $deliveryAddress The delivery address row the cart points at.
     * @param int $customerId Whose fields decide which column holds the place.
     *
     * @return int|null The place, or null when nobody knows.
     *
     * @since  2.2.1
     */
    public function placeOf(?object $deliveryAddress, int $customerId): ?int
    {
        if ($this->bound !== null) {
            return $this->bound;
        }

        $name = (string) (FieldsHelper::deliveryPlaceField($customerId)->field_name ?? '');
        $placeId = $name !== '' && $deliveryAddress !== null ? (int) ($deliveryAddress->{$name} ?? 0) : 0;

        return $placeId > 0 ? $placeId : null;
    }
}
