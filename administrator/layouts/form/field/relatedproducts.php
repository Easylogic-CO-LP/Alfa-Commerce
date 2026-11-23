<?php

/**
 * Alfa Commerce
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * A product's Related tab (RelatedProductsField): Joomla's repeatable table of rows, each a product picker and its
 * variant cell. A row whose product changes gets its variant cell from $variantUrl, drawn by the server as the field
 * draws it (media js/admin/related-products.js). A template overrides this layout at
 * html/layouts/com_alfa/form/field/relatedproducts.php.
 *
 * Receives Joomla's subform layout data and $variantUrl.
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Layout\LayoutHelper;

// com_alfa's asset registry, read here too: a page of another component, or a raw AJAX render, has not read it.
$assets = Factory::getApplication()->getDocument()->getWebAssetManager();
$assets->getRegistry()->addExtensionRegistryFile('com_alfa');
$assets->useStyle('com_alfa.admin.related-products')
    ->useScript('com_alfa.admin.related-products');
?>
<div class="alfa-related-products" data-variant-url="<?php echo htmlspecialchars((string) $displayData['variantUrl'], ENT_QUOTES, 'UTF-8'); ?>">
    <?php echo LayoutHelper::render('joomla.form.field.subform.repeatable-table', $displayData); ?>
</div>
