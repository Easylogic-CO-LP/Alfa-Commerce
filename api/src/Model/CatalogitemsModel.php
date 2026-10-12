<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\Model;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Model\ItemsModel as SiteItemsModel;

/**
 * Storefront product listing, served by the SITE model.
 *
 * Same visibility rules as the catalogue (published, inside the publish
 * window and allowed by CatalogAccessPolicy) with per-row pricing and the
 * resolved default combination.
 *
 * @since  1.9.0
 */
class CatalogitemsModel extends SiteItemsModel
{
    use SanitisesCatalogPayload;

    /**
     * Get the storefront rows, made safe for the wire.
     *
     * @return array
     *
     * @since  1.9.0
     */
    public function getItems()
    {
        $this->applyPricingContext();

        // Surfaces are HTML the client cannot use; media and pricing stay ON,
        // because a catalogue listing without a picture or a price is useless.
        $this->setState('plugin.layouts', false);

        $items = parent::getItems();

        if (!\is_array($items)) {
            return $items;
        }

        foreach ($items as $item) {
            if (\is_object($item)) {
                $this->sanitiseCatalogItem($item);
            }
        }

        return $items;
    }
}
