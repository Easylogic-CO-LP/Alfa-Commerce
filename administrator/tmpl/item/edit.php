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
    use \Joomla\CMS\Factory;
    use Joomla\CMS\Layout\LayoutHelper;
    use \Joomla\CMS\Router\Route;
    use \Joomla\CMS\Language\Text;

    $wa = $this->document->getWebAssetManager();
    $wa->useStyle('com_alfa.admin')
        ->useScript('keepalive')
        ->useScript('form.validate')
        ->useScript('com_alfa.validate.engine')
        ->useStyle('com_alfa.validate');


    $input = Factory::getApplication()->getInput();

?>

<form
        action="<?php echo Route::_('index.php?option=com_alfa&layout=edit&id=' . (int) $this->item->id); ?>"
        method="post" enctype="multipart/form-data" name="adminForm" id="item-form" class="form-validate form-horizontal">

    <div class="row title-alias form-vertical mb-3">
        <div class="col-12 col-md-6">
            <?php echo $this->form->renderField('name'); ?>
        </div>
        <div class="col-12 col-md-6">
            <?php echo $this->form->renderField('alias'); ?>
        </div>
    </div>


    <?php echo HTMLHelper::_('uitab.startTabSet', 'myTab', ['active' => 'general', 'recall' => true, 'breakpoint' => 768]); ?>
    <?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'general', Text::_('COM_ALFA_TAB_ITEM', true)); ?>
    <div class="row">
        <div class="col-lg-9">
            <div class="form-vertical">
                <?php // Identity leads, like the combination Studio. The columns are
                      // CSS, so a fourth code added to the fieldset needs no change
                      // here. ?>
                <div class="alfa-identity-grid">
                    <?php echo $this->form->renderFieldset('identity'); ?>
                </div>

                <?php echo $this->form->renderFieldset('general'); ?>
            </div>
        </div>
        <div class="col-lg-3">
            <?php // Default first: it is the required one, and ItemModel adds it to
                  // `categories` on save when it is missing, so a merchant who fills
                  // only this field already has a correctly filed product. Categories
                  // below it is for the extra placements. ?>
            <?php echo $this->form->renderField('id_category_default'); ?>
            <?php echo $this->form->renderField('categories'); ?>

            <?php echo $this->form->renderField('manufacturers'); ?>
            <?php echo $this->form->renderField('state'); ?>
            <?php if ($this->state->params->get('save_history', 1)) : ?>
                <div class="control-group">
                    <div class="control-label"><?php echo $this->form->getLabel('version_note'); ?></div>
                    <div class="controls"><?php echo $this->form->getInput('version_note'); ?></div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'logistics', Text::_('COM_ALFA_TAB_ITEM_LOGISTICS')); ?>
    <?php // Where this product's stock came from and where it went. Both are links
          // into grids that already page, sort and filter; nothing is queried here,
          // and no count is shown, because a count is a query per page load. ?>
    <?php if ((int) $this->item->id > 0) : ?>
        <?php
            $alfaRoot = \Joomla\CMS\Uri\Uri::root(true);
            $alfaPanels = [
                [
                    'id'   => 'alfaItemMovementsModal',
                    'icon' => 'inventory.svg',
                    'text' => 'COM_ALFA_ITEM_SHOW_MOVEMENTS',
                    'desc' => 'COM_ALFA_ITEM_SHOW_MOVEMENTS_DESC',
                    'url'  => 'index.php?option=com_alfa&view=inventorymovements&tmpl=component&modal=1'
                        . '&filter[search]=id:' . (int) $this->item->id,
                ],
                [
                    'id'   => 'alfaItemOrdersModal',
                    'icon' => 'orders.svg',
                    'text' => 'COM_ALFA_ITEM_SHOW_ORDERS',
                    'desc' => 'COM_ALFA_ITEM_SHOW_ORDERS_DESC',
                    'url'  => 'index.php?option=com_alfa&view=orders&tmpl=component&modal=1'
                        . '&filter[item_id]=' . (int) $this->item->id,
                ],
            ];
        ?>
        <div class="d-flex flex-wrap gap-2 mb-3">
            <?php foreach ($alfaPanels as $alfaPanel) : ?>
                <button type="button" class="btn btn-sm btn-outline-secondary"
                        data-bs-toggle="modal" data-bs-target="#<?php echo $alfaPanel['id']; ?>"
                        title="<?php echo $this->escape(Text::_($alfaPanel['desc'])); ?>">
                    <span class="alfa-icon" aria-hidden="true"
                          style="--alfa-icon:url('<?php echo $alfaRoot; ?>/media/com_alfa/images/admin/menu/<?php echo $alfaPanel['icon']; ?>')"></span>
                    <?php echo Text::_($alfaPanel['text']); ?>
                </button>
            <?php endforeach; ?>
        </div>
        <?php // renderModal with a url does NOT put a live iframe in the page: it parks the
              // markup in data-iframe and the joomla-modal element injects it on open. So the
              // grid behind each button is queried when it is asked for, never on page load. ?>
        <?php foreach ($alfaPanels as $alfaPanel) : ?>
            <?php echo HTMLHelper::_('bootstrap.renderModal', $alfaPanel['id'], [
                'title'      => Text::_($alfaPanel['text']),
                'url'        => Route::_($alfaPanel['url']),
                'height'     => '100%',
                'width'      => '100%',
                'bodyHeight' => 70,
                'modalWidth' => 90,
                'footer'     => '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">'
                    . Text::_('JTOOLBAR_CLOSE') . '</button>',
            ]); ?>
        <?php endforeach; ?>
    <?php endif; ?>
    <div class="row">
        <?php // Order and the separating rules live in forms/item.xml (spacers),
              // so the whole tab is one call. ?>
        <div class="col-12">
            <?php echo $this->form->renderFieldset('inventory'); ?>
        </div>
    </div>
    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'combinations', Text::_('COM_ALFA_TAB_ITEM_COMBINATIONS', true)); ?>
    <div class="row">
        <div class="col-12">
            <fieldset id="fieldset-pricedata" class="options-form">
                <legend><?php echo Text::_('COM_ALFA_FORM_FIELDSET_PRICE'); ?></legend>
                <?php echo $this->form->renderField('prices'); ?>
            </fieldset>
        </div>
    </div>
    <?php echo $this->loadTemplate('combinations'); ?>
    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'dropzone', Text::_('COM_ALFA_MEDIA_TAB_ITEM_LABEL')); ?>
    <div class="row">
        <?php echo $this->form->renderFieldset('Images'); ?>
    </div>
    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'downloads', Text::_('COM_ALFA_TAB_ITEM_DOWNLOADS', true)); ?>
    <?php echo $this->loadTemplate('downloads'); ?>
    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'publishing', Text::_('JGLOBAL_FIELDSET_PUBLISHING')); ?>
    <div class="row">
        <div class="col-12">
            <fieldset id="fieldset-publishingdata" class="options-form">
                <legend><?php echo Text::_('JGLOBAL_FIELDSET_PUBLISHING'); ?></legend>
                <?php echo $this->form->renderFieldset('publish'); ?>
            </fieldset>
        </div>
    </div>
    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'seo', Text::_('COM_ALFA_TAB_SEO')); ?>
    <div class="row">
        <div class="col-12">
            <fieldset id="fieldset-metadata" class="options-form">
                <legend><?php echo Text::_('JGLOBAL_FIELDSET_METADATA_OPTIONS'); ?></legend>
                <div>
                    <?php echo $this->form->renderFieldset('meta'); ?>

                    <?php
                        // One SEO preview per content language (tabset when >1).
                        echo LayoutHelper::render('seo.multilingual', [
                            'itemId'                  => $this->item->id ?? 0,
                            'itemType'                => 'item',
                            'table'                   => '#__alfa_items',
                            'pk'                       => 'id_item',
                            'robots'                  => $this->item->robots ?? '',
                            'focusKeyword'            => $this->item->focus_keyword ?? '',
                            'contentField'            => 'short_desc',
                            'additionalContentFields' => ['full_desc' => 'full_desc'],
                        ]);
                    ?>

                </div>
            </fieldset>
        </div>
    </div>
    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php
    // EXTENSIBILITY: every form fieldset beyond the core ones renders as its
    // own tab: a plugin injecting fields into the com_alfa.item form via the
    // standard onContentPrepareForm (Form XML with a new <fieldset>) gets a
    // tab here with zero template changes. Same convention as the itemfield,
    // formfield, payment and shipment edit screens.
    $coreFieldsets = ['general', 'identity', 'inventory', 'prices', 'Images', 'publish', 'meta'];

    foreach ($this->form->getFieldsets() as $fieldsetName => $fieldset) {
        if (in_array($fieldsetName, $coreFieldsets, true)) {
            continue;
        }

        $fieldsetLabel = !empty($fieldset->label)
            ? Text::_($fieldset->label)
            : ucwords(str_replace('_', ' ', $fieldsetName));

        echo HTMLHelper::_('uitab.addTab', 'myTab', $fieldsetName, $fieldsetLabel, true);
        ?>
        <div class="row">
            <div class="col-lg-12">
                <?php echo $this->form->renderFieldset($fieldsetName); ?>
            </div>
        </div>
        <?php
        echo HTMLHelper::_('uitab.endTab');
    }
    ?>

    <?php echo HTMLHelper::_('uitab.endTabSet'); ?>

    <?php echo $this->form->renderControlFields(); ?>

</form>