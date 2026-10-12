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
        ->useScript('core')
        ->useScript('keepalive')
        ->useScript('form.validate')
        ->useScript('com_alfa.validate.engine')
        ->useStyle('com_alfa.validate');

    $input = Factory::getApplication()->getInput();
?>

<form
        action="<?php echo Route::_('index.php?option=com_alfa&layout=edit&id=' . (int) $this->item->id); ?>"
        method="post" enctype="multipart/form-data" name="adminForm" id="category-form"
        class="form-validate form-horizontal"
        aria-label="<?php echo Text::_('COM_ALFA_CATEGORY_FORM_TITLE_' . ((int) $this->item->id === 0 ? 'NEW' : 'EDIT'), true); ?>"
>

    <div class="row title-alias form-vertical mb-3">
        <div class="col-12 col-md-6">
            <?php echo $this->form->renderField('name'); ?>
        </div>
        <div class="col-12 col-md-6">
            <?php echo $this->form->renderField('alias'); ?>
        </div>
    </div>

    <?php echo HTMLHelper::_('uitab.startTabSet', 'myTab', ['active' => 'general', 'recall' => true, 'breakpoint' => 768]); ?>
    <?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'general', Text::_('COM_ALFA_TAB_CATEGORY', true)); ?>
    <div class="row">
        <div class="col-lg-9">
            <div>
                <fieldset class="adminform">
                    <div class="mb-3">
                        <?php echo $this->form->getLabel('desc'); ?>
                    </div>
                    <?php echo $this->form->getInput('desc'); ?>
                </fieldset>
            </div>
        </div>
        <div class="col-lg-3">
            <?php echo $this->form->renderField('parent_id'); ?>

            <?php echo $this->form->renderField('state'); ?>

            <?php echo $this->form->renderField('version_note'); ?>

            <fieldset class="options-form">
                <legend><?php echo Text::_('COM_ALFA_LIST_OPTIONS'); ?></legend>
                <div>
                    <?php echo $this->form->renderFieldset('list_options'); ?>
                </div>
            </fieldset>
        </div>
    </div>
    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'dropzone', Text::_( 'COM_ALFA_MEDIA_TAB_LABEL')); ?>
    <div class="row">
        <fieldset>
            <?php echo $this->form->renderFieldset('Image'); ?>
        </fieldset>
    </div>
    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'publishing', Text::_('JGLOBAL_FIELDSET_PUBLISHING')); ?>
    <div class="row">
        <div class="col-12">
            <fieldset id="fieldset-publishingdata" class="options-form">
                <legend><?php echo Text::_('JGLOBAL_FIELDSET_PUBLISHING'); ?></legend>
                <div>
                    <?php echo $this->form->renderFieldset('publish'); ?>
                </div>
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
                            'itemType'                => 'category',
                            'table'                   => '#__alfa_categories',
                            'pk'                      => 'id_category',
                            'robots'                  => $this->item->robots ?? '',
                            'focusKeyword'            => $this->item->focus_keyword ?? '',
                            'contentField'            => 'desc',
                            'additionalContentFields' => [],
                        ]);
                    ?>
                </div>
            </fieldset>
        </div>
    </div>
    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php
    // EXTENSIBILITY: every form fieldset beyond the core ones renders as its
    // own tab (same convention as the item edit screen), so the layouts
    // fieldset (and any plugin-injected one) gets a tab with zero template changes.
    // list_options renders in the Category tab's side panel above, not as a tab.
    $coreFieldsets = ['general', 'publish', 'meta', 'Image', 'list_options'];

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