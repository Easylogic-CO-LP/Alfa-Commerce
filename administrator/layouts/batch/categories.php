<label id="batch-category-lbl" for="batch-category-id">
    Category to select:
</label>

<?php
/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;

$app = Factory::getApplication();
$db  = Factory::getContainer()->get('DatabaseDriver');

$app->getDocument()->getWebAssetManager()
        ->usePreset('choicesjs')
        ->useScript('webcomponent.field-fancy-select');

$queryCat = $db->getQuery(true)
        ->select($db->quoteName('a.id'))
        ->from($db->quoteName('#__alfa_categories', 'a'));

MultilingualHelper::addMultilingualJoinToQuery(
        query:             $queryCat,
        mainAlias:         'a',
        mainPrimaryColumn: 'id',
        langTableBase:     '#__alfa_categories',
        langPrimaryColumn: 'id_category',
        fields:            ['name']
);

$db->setQuery($queryCat);
$categories = $db->loadAssocList();

$catOptions = [];
foreach ($categories as $category) {
    $name = !empty($category['name']) ? $category['name'] : 'ID: ' . $category['id'];
    $catOptions[] = HTMLHelper::_('select.option', $category['id'], $name);
}
?>

<joomla-field-fancy-select>
    <?php echo HTMLHelper::_(
            'select.genericlist',
            $catOptions,
            'batch[category_id][]',
            array(
                    'list.attr'   => 'class="form-select" id="batch-category-id" multiple data-placeholder="- Select a category -"',
                    'list.select' => ''
            )
    ); ?>
</joomla-field-fancy-select>