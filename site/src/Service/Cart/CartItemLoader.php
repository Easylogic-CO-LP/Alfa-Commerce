<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Cart;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Service\Catalog\CatalogAccessPolicy;
use Alfa\Component\Alfa\Administrator\Service\Catalog\StockVisibilityPolicy;
use Alfa\Component\Alfa\Site\Helper\ItemFieldsHelper;
use Alfa\Component\Alfa\Site\Service\Pricing\ActiveDateCondition;
use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

/**
 * The catalogue as the cart sees one product.
 *
 * The row under the catalogue's own access policy (always) and under its
 * publish window only when the caller says a purchase decision is being made;
 * with the product's fields, options, combinations and informational data
 * attached. And the name alone, for a line whose product has left the
 * catalogue and must still be shown as the thing it was.
 *
 * @since  2.0.0
 */
final class CartItemLoader
{
    /** @since 2.0.0 */
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * One product, configured, or null when it is not there for this shopper.
     *
     * @param int $itemId The product.
     * @param bool $requirePublished Whether it must be on sale right now.
     *
     * @since  2.0.0
     */
    public function load(int $itemId, bool $requirePublished = false): ?object
    {
        $db = $this->db;
        $query = $db->getQuery(true)
            ->select('a.*')
            ->from($db->qn('#__alfa_items', 'a'))
            ->where($db->qn('a.id') . ' = ' . (int) $itemId);

        (new CatalogAccessPolicy())->applyToItems($query, 'a');

        if ($requirePublished) {
            // ON SALE RIGHT NOW MEANS WHAT THE CATALOGUE MEANS BY IT.
            //
            // A cart line is shown as unavailable when the listing model will
            // not return its product, and that model asks four questions, not
            // three: the state, the publish window, the access policy, and the
            // merchant's hide-out-of-stock rule. Asking only the first three
            // here let a product the shop has withdrawn from sale for being out
            // of stock still answer a purchase decision, so the cart page
            // called the line unavailable while the write boundary let its
            // quantity be changed, and the shelf then cut that change to
            // nothing, which removes the line the shopper was told to remove
            // themselves.
            $now = $db->quote(ActiveDateCondition::now());
            $query->where($db->qn('a.state') . ' = 1')
                ->where('(' . $db->qn('a.publish_up') . ' IS NULL OR ' . $db->qn('a.publish_up') . ' <= ' . $now . ')')
                ->where('(' . $db->qn('a.publish_down') . ' IS NULL OR ' . $db->qn('a.publish_down') . ' >= ' . $now . ')');

            (new StockVisibilityPolicy())->applyToItems($query, 'a');
        }

        $item = $db->setQuery($query)->loadObject();

        if (!$item) {
            return null;
        }

        $data = ItemFieldsHelper::getDataForItem($itemId);
        $item->fields = $data->fields;
        $item->options = $data->options;
        $item->combinations = $data->combinations;
        $item->informational = $data->informational;

        return $item;
    }

    /**
     * The product's name in the current language, for a stranded line.
     *
     * @since  2.0.0
     */
    public function name(int $itemId): string
    {
        $db = $this->db;
        $query = $db->getQuery(true)
            ->select($db->qn('a.id'))
            ->from($db->qn('#__alfa_items', 'a'))
            ->where($db->qn('a.id') . ' = ' . (int) $itemId);

        MultilingualHelper::addMultilingualJoinToQuery($query, 'a', 'id', '#__alfa_items', 'id_item', ['name']);

        return (string) ($db->setQuery($query)->loadObject()->name ?? '');
    }
}
