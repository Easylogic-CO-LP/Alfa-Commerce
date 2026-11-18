<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\Model;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Model\ItemModel as SiteItemModel;
use Joomla\CMS\MVC\Controller\Exception\ResourceNotFound;
use Throwable;

/**
 * Storefront view of a single product, served by the SITE model.
 *
 * This is the catalogue representation, not the back-office one: the site
 * model enforces state=1, the publish window and CatalogAccessPolicy, and it
 * returns the priced, variant-resolved object with fields, options,
 * combinations, media, categories and manufacturers, the same assembly the
 * product page renders from.
 *
 * @since  2.0.0
 */
class CatalogitemModel extends SiteItemModel
{
    use SanitisesCatalogPayload;

    /**
     * Get the storefront item, made safe for the wire.
     *
     * @param int|null $pk The record id.
     *
     * @return mixed The storefront item.
     *
     * @throws ResourceNotFound When the product is missing, unpublished,
     *                          outside its publish window or not visible to
     *                          this identity.
     *
     * @since  2.0.0
     */
    public function getItem($pk = null): mixed
    {
        $this->applyPricingContext();

        // No HTML surfaces: dispatchFieldSurfaces() boots one plugin per field
        // purely to build markup a JSON client cannot use.
        $this->setState('plugin.layouts', false);

        // ApiController::displayItem() stores the id under "<modelName>.id",
        // i.e. catalogitem.id, while the site model reads item.id, and the
        // view calls getItem() with no argument, so without this bridge the pk
        // is always 0 and every request 404s.
        $pk = (int) ($pk ?: $this->getState($this->getName() . '.id') ?: $this->getState('item.id'));

        try {
            $item = parent::getItem($pk);
        } catch (Throwable $e) {
            // The site model signals "not visible" with a bare \Exception(404),
            // and JsonapiRenderer only maps its registered typed exceptions:
            // an unmapped one renders as 500. Restate it as a real 404 so a
            // hidden product answers exactly as it does on the storefront.
            if ((int) $e->getCode() === 404) {
                throw new ResourceNotFound($e->getMessage(), 404, $e);
            }

            throw $e;
        }

        if (!$item || empty($item->id)) {
            throw new ResourceNotFound('Item not found', 404);
        }

        $this->sanitiseCatalogItem($item);

        return $item;
    }

    /**
     * Suppress the buy-form URLs.
     *
     * buildBuyUrls() runs outside the plugin.layouts opt-out and calls
     * Route::_(), which is fatal in the API application: ApiRouter extends the
     * framework router, which has no build(), and Route::_ only catches
     * RuntimeException so the resulting Error escapes uncaught.
     *
     * @param object $data The item being assembled.
     *
     *
     * @since  2.0.0
     */
    protected function buildBuyUrls(object $data): void
    {
        // Intentionally empty: storefront-only concern.
    }
}
