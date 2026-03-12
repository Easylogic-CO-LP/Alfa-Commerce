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
        ->useScript('form.validate');

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

        </div>
    </div>
    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'dropzone', 'COM_ALFA_MEDIA_TAB_LABEL'); ?>
    <div class="row">
        <fieldset>
            <?php echo $this->form->renderFieldset('Image'); ?>
        </fieldset>
    </div>
    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'publishing', Text::_('COM_ALFA_FIELDSET_PUBLISHING_SEO')); ?>
    <div class="row">
        <div class="col-12 col-lg-6">
            <fieldset id="fieldset-publishingdata" class="options-form">
                <legend><?php echo Text::_('JGLOBAL_FIELDSET_PUBLISHING'); ?></legend>
                <div>
                    <?php echo $this->form->renderFieldset('publish'); ?>
                </div>
            </fieldset>
        </div>
        <div class="col-12 col-lg-6">
            <fieldset id="fieldset-metadata" class="options-form">
                <legend><?php echo Text::_('JGLOBAL_FIELDSET_METADATA_OPTIONS'); ?></legend>
                <div>
                    <?php echo $this->form->renderFieldset('meta'); ?>

                    <?php
                        echo LayoutHelper::render(
                            'seo.preview',
                            (new \Alfa\Component\Alfa\Administrator\Controller\SeoController())->getResultObject(
                                itemId: $this->item->id ?? 0,
                                title: $this->item->name ?? '',
                                metaTitle: $this->item->meta_title ?? '',
                                metaDesc: $this->item->meta_desc ?? '',
                                alias: $this->item->alias ?? '',
                                defaultAlias: $this->item->alias ?? '',
                                content: $this->item->desc ?? '',
                                focusKeyword: $this->item->focus_keyword ?? '',
                                itemType: 'category',
                                robots: $this->item->robots ?? '',
                                fieldJsSelectors: [
                                    'title'        => '#jform_name',
                                    'metaTitle'    => '#jform_meta_title',
                                    'metaDesc'     => '#jform_meta_desc',
                                    'alias'        => '#jform_alias',
                                    'robots'       => '#jform_robots',
                                    'content'      => '#jform_desc',
                                    'focusKeyword' => '[data-seo-focus-keyword-field]'
                                ]
                            ));
                    ?>
                </div>
            </fieldset>
        </div>
    </div>
    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php echo HTMLHelper::_('uitab.endTabSet'); ?>

    <?php echo $this->form->renderControlFields(); ?>

</form>