<label id="batch-manufacturer-lbl" for="batch-manufacturer-id">
    Manufacturer to select:
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

$queryMan = $db->getQuery(true)
        ->select($db->quoteName('a.id'))
        ->from($db->quoteName('#__alfa_manufacturers', 'a'));

MultilingualHelper::addMultilingualJoinToQuery(
        query:             $queryMan,
        mainAlias:         'a',
        mainPrimaryColumn: 'id',
        langTableBase:     '#__alfa_manufacturers',
        langPrimaryColumn: 'id_manufacturer',
        fields:            ['name']
);

$db->setQuery($queryMan);
$manufacturers = $db->loadAssocList();

$manOptions = [];
foreach ($manufacturers as $manufacturer) {
    $name = !empty($manufacturer['name']) ? $manufacturer['name'] : 'ID: ' . $manufacturer['id'];
    $manOptions[] = HTMLHelper::_('select.option', $manufacturer['id'], $name);
}
?>

<joomla-field-fancy-select>
    <?php echo HTMLHelper::_(
            'select.genericlist',
            $manOptions,
            'batch[manufacturer_id][]',
            array(
                    'list.attr'   => 'class="form-select" id="batch-manufacturer-id" multiple data-placeholder="- Select a manufacturer -"',
                    'list.select' => ''
            )
    );
    ?>
</joomla-field-fancy-select>