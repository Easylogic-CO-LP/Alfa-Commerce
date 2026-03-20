<?php

	/**
	 * @package    Alfa Commerce
	 * @author     Agamemnon Fakas <info@easylogic.gr>
	 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
	 * @license    GNU General Public License version 3 or later; see LICENSE
	 */
	// No direct access
	defined('_JEXEC') or die;

	use \Joomla\CMS\HTML\HTMLHelper;
	use \Joomla\CMS\Uri\Uri;
	use \Joomla\CMS\Router\Route;
	use \Joomla\CMS\Language\Text;
	use \Joomla\CMS\Layout\LayoutHelper;

	// Import CSS
	$wa = $this->document->getWebAssetManager();
	$wa->useStyle('com_alfa.list');
?>

<form action="<?php echo htmlspecialchars(Uri::getInstance()->toString()); ?>" method="post"
      name="adminForm" id="adminForm">
	<?php if (!empty($this->filterForm))
	{
		echo LayoutHelper::render('joomla.searchtools.default', array('view' => $this));
	} ?>

    <input type="hidden" name="task" value=""/>
    <input type="hidden" name="boxchecked" value="0"/>
    <input type="hidden" name="filter_order" value=""/>
    <input type="hidden" name="filter_order_Dir" value=""/>
	<?php echo HTMLHelper::_('form.token'); ?>
</form>


<section>
    <div class="category-list list-container">
		<?php foreach ($this->items as $item) : ?>
            <article>
                <div class="category-item list-item">
                    <a href="<?php echo $item->link; ?>">
						<?php if (!empty($item->medias[0])): ?>
                            <img src=<?= $item->medias[0]->path ?>>
						<?php endif; ?>
                    </a>

                    <div class="category-title item-title">
                        <h3><?php echo $this->escape($item->name); ?></h3>
                    </div>

                    <div class="category-description">
						<?php echo $this->escape($item->desc); ?>
                    </div>
                    <div class="category-products">
                        <a href="<?php echo $item->link; ?>">
							<?php echo Text::_('COM_ALFA_CATEGORY_SHOW_ALL_PRODUCTS'); ?> </a>
                    </div>

<!--                    <div class="category-url">-->
<!--                        <a href="--><?php //echo Route::_('index.php?option=com_alfa&view=category&id=' . (int) $item->id); ?><!--">-->
<!--							--><?php //echo Text::_('COM_ALFA_CATEGORY_DETAILS'); ?><!-- </a>-->
<!--                    </div>-->
                </div>

            </article>
		<?php endforeach; ?>
    </div>
</section>
