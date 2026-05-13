<?php
/**
 * @version    1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Layout\LayoutHelper;

$app = Factory::getApplication();
$params = $app->getParams();

$wa = $this->document->getWebAssetManager();
$wa->useStyle('com_alfa.list')
    ->useStyle('com_alfa.item')
    ->useScript('com_alfa.item.recalculate')
    ->useScript('com_alfa.item.addtocart');

// ============================================================================
// PRICE SETTINGS - CUSTOMIZABLE
// ============================================================================
// Default: Use settings from view (resolved by user group)
$priceSettings = $this->priceSettings;

$wa = $this->document->getWebAssetManager();
$wa->addInlineScript(<<<'JS'
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('adminForm');

        if (!form) {
            return;
        }

        const nativeSubmit = HTMLFormElement.prototype.submit;
        
        form.submit = function() {
            // Disable empty fields
            form.querySelectorAll('input, select, textarea').forEach(function(field) {
                if (field.name && field.value.trim() === '') {
                    field.disabled = true;
                }
            });
            
            nativeSubmit.call(form);
        };

        form.addEventListener('submit', function() {
            form.querySelectorAll('input, select, textarea').forEach(function(field) {
                if (field.name && field.value.trim() === '') {
                    field.disabled = true;
                }
            });
        });
    });
JS);

//echo '<pre>';
//print_r($this->items);
//echo '</pre>';
//exit();

?>

<?php echo $this->loadTemplate('categories'); ?>

<?php if (!empty($this->filterForm)): ?>
    <form action="<?php echo htmlspecialchars(Uri::getInstance()->toString()); ?>" method="get" name="adminForm"
          id="adminForm">
        <?php
            echo LayoutHelper::render('joomla.searchtools.default', array('view' => $this));
            //		echo $this->filterForm->renderControlFields();
        ?>
    </form>
<?php endif; ?>

<section>
    <?php echo $this->pagination->getListFooter(); ?>
    <div class="list-container items-list">
        <?php foreach ($this->items as $item) : ?>
            <article class="list-item" data-item-id="<?php echo $item->id; ?>">

                <?php if (!empty($item->medias[0]->thumbnail)): ?>
                    <div class="item-image">
                        <a href="<?= $item->link ?>">
                            <img src="<?= $item->medias[0]->thumbnail ?>" alt="<?= $item->name ?>" />
                        </a>
                    </div>
                <?php endif; ?>

                <div class="item-title">
                    <a href="<?= $item->link ?>">
                        <?php echo $this->escape($item->name); ?></a>
                </div>

                <?php echo LayoutHelper::render('price', [ 'item' => $item,  'settings' => $priceSettings ]); ?>

                <?php echo LayoutHelper::render('stock_info', ['item' => $item]); ?>

                <?php echo LayoutHelper::render('add_to_cart', $item); ?>

            </article>

        <?php endforeach; ?>
    </div>
    <?php echo $this->pagination->getListFooter(); ?>
</section>