<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Controller\SeoController;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\LanguageHelper;
use Joomla\CMS\Layout\LayoutHelper;

/**
 * Renders the SEO preview once per installed content language — each in its own
 * tab (collapsing to a single panel when only one language exists). Every panel
 * is seeded with that language's stored name/meta/alias/content and points its
 * live field tracking at that language's inputs (jform_<field>_<langtag>).
 *
 * Expected $displayData keys:
 * @var   int     $displayData['itemId']
 * @var   string  $displayData['itemType']                'category' | 'item'
 * @var   string  $displayData['table']                   e.g. '#__alfa_categories'
 * @var   string  $displayData['pk']                      e.g. 'id_category'
 * @var   string  $displayData['robots']                  current robots value (not translatable)
 * @var   string  $displayData['focusKeyword']            UI-only analysis keyword
 * @var   string  $displayData['contentField']            jform base name used as SEO content (e.g. 'desc')
 * @var   array   $displayData['additionalContentFields'] [logicalKey => jform base name], optional
 */

$itemId                  = (int) ($displayData['itemId'] ?? 0);
$itemType                = $displayData['itemType'] ?? 'category';
$table                   = $displayData['table'] ?? '#__alfa_categories';
$pk                      = $displayData['pk'] ?? 'id_category';
$robots                  = $displayData['robots'] ?? '';
$focusKeyword            = $displayData['focusKeyword'] ?? '';
$contentField            = $displayData['contentField'] ?? 'desc';
$additionalContentFields = $displayData['additionalContentFields'] ?? [];

$languages = LanguageHelper::getLanguages('lang_code');

// All translatable values for this record, flat-keyed (name_en_gb, alias_el_gr…).
$flat = MultilingualHelper::getMultilingualDataFlat(
    currentId:         $itemId,
    primaryColumnName: $pk,
    tableName:         $table,
);

$controller = new SeoController();

/**
 * Render one SEO preview panel for a single language code.
 */
$renderPreview = function (string $langCode) use (
    $itemId, $itemType, $robots, $focusKeyword, $contentField, $additionalContentFields, $flat, $controller
): string {
    $tag = strtolower(str_replace('-', '_', $langCode));

    $additionalSelectors = [];
    $additionalValues    = [];

    foreach ($additionalContentFields as $key => $jformBase) {
        $additionalSelectors[$key] = '#jform_' . $jformBase . '_' . $tag;
        $additionalValues[$key]    = $flat[$jformBase . '_' . $tag] ?? '';
    }

    $selectors = [
        'title'             => '#jform_name_' . $tag,
        'metaTitle'         => '#jform_meta_title_' . $tag,
        'metaDesc'          => '#jform_meta_desc_' . $tag,
        'alias'             => '#jform_alias_' . $tag,
        'content'           => '#jform_' . $contentField . '_' . $tag,
        'robots'            => '#jform_robots',
        'focusKeyword'      => '#seo-focus-keyword-input-' . $tag,
        'additionalContent' => $additionalSelectors,
    ];

    $data = $controller->getResultObject(
        itemId:            $itemId,
        title:             $flat['name_' . $tag] ?? '',
        metaTitle:         $flat['meta_title_' . $tag] ?? '',
        metaDesc:          $flat['meta_desc_' . $tag] ?? '',
        alias:             $flat['alias_' . $tag] ?? '',
        defaultAlias:      $flat['alias_' . $tag] ?? '',
        content:           $flat[$contentField . '_' . $tag] ?? '',
        additionalContent: $additionalValues,
        focusKeyword:      $focusKeyword,
        itemType:          $itemType,
        robots:            $robots,
        fieldJsSelectors:  $selectors,
    );

    $data->lang = $tag;

    return LayoutHelper::render('seo.preview', $data);
};

// Single content language → no tabs, just the one panel.
if (count($languages) <= 1) {
    echo $renderPreview((string) array_key_first($languages));
    return;
}

$tabSet   = 'seoMlTab_' . $itemType;
$firstTag = strtolower(str_replace('-', '_', (string) array_key_first($languages)));

echo HTMLHelper::_('uitab.startTabSet', $tabSet, ['active' => $tabSet . '_' . $firstTag]);

foreach ($languages as $langCode => $language) {
    $tag = strtolower(str_replace('-', '_', $langCode));

    echo HTMLHelper::_('uitab.addTab', $tabSet, $tabSet . '_' . $tag, $language->title ?? $langCode);
    echo $renderPreview((string) $langCode);
    echo HTMLHelper::_('uitab.endTab');
}

echo HTMLHelper::_('uitab.endTabSet');
