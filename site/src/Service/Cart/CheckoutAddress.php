<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Cart;

use Alfa\Component\Alfa\Administrator\Helper\FieldsHelper;
use Alfa\Component\Alfa\Administrator\Helper\RoleRegistry;
use Alfa\Component\Alfa\Administrator\Helper\UserInfoHelper;
use Joomla\CMS\Factory;

defined('_JEXEC') or die;

/**
 * The delivery address a storefront checkout holds, read the same way by the checkout page, the cart module and the
 * placement (2.2.1).
 *
 * What the shopper typed in this checkout, kept in the user state across its pages and redirects; with nothing typed,
 * a signed-in customer's saved address the checkout fills in by itself (their only saved address, unless they chose
 * to enter new details). The checkout form's own reading (the cart model) and the opened cart's delivery binding both
 * ask here, so the cart is priced at the place the checkout shows from the first page that shows the cart.
 *
 * @since  2.2.1
 */
final class CheckoutAddress
{
    /**
     * A customer's saved addresses the checkout offers: rows with a value on an active checkout field other than the
     * identity fields (a row holding only the name a registration wrote is not an address).
     *
     * @param int $userId The customer's account; 0 for a guest, who has none.
     *
     * @return object[]
     *
     * @since  2.2.1
     */
    public static function saved(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $roleColumns = array_filter(array_map([RoleRegistry::class, 'column'], RoleRegistry::identityRoles()));
        $activeKeys = array_diff(array_column(FieldsHelper::getFields('cart.form'), 'field_name'), $roleColumns);
        $rows = [];

        foreach (UserInfoHelper::getRowsByUser(userId: $userId) as $row) {
            foreach ($activeKeys as $key) {
                if (trim((string) ($row->$key ?? '')) !== '') {
                    $rows[] = $row;
                    break;
                }
            }
        }

        return $rows;
    }

    /**
     * The delivery place the checkout holds for this visitor: the place typed in this checkout, else the place of the
     * customer's only saved address, unless they chose to enter new details.
     *
     * @param int $userId The visitor's account; 0 for a guest.
     *
     * @return int|null The place, or null when the checkout holds none (or the shop maps no delivery place field).
     *
     * @since  2.2.1
     */
    public static function place(int $userId): ?int
    {
        $column = (string) (FieldsHelper::deliveryPlaceField()->field_name ?? '');

        if ($column === '') {
            return null;
        }

        $app = Factory::getApplication();
        $typed = (array) $app->getUserState('com_alfa.cart.form_data', []);

        // What was typed stands as typed: an emptied place is no place, never the saved address behind it.
        if ($typed !== []) {
            $placeId = (int) ($typed[FieldsHelper::FIELDS_KEY][$column] ?? 0);

            return $placeId > 0 ? $placeId : null;
        }

        if ($app->getUserState('com_alfa.cart.userinfo_pick') === 'new') {
            return null;
        }

        $rows = self::saved(userId: $userId);
        $placeId = \count($rows) === 1 ? (int) ($rows[0]->{$column} ?? 0) : 0;

        return $placeId > 0 ? $placeId : null;
    }
}
