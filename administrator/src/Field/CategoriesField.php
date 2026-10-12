<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Catalog\CatalogAccessPolicy;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;

class CategoriesField extends ListField
{
    protected $type = 'categories';
    protected $options = [];

    /**
     * Build the category options from the configured model (default 'categories'),
     * rendered as a depth-indented or full-path tree. When 'disableDescendants'
     * is set on a self-referential picker, disables the current node and its
     * descendants to prevent a category from becoming its own parent (cycle).
     *
     * @return array The option set keyed by 'cat-<id>'
     * @since  1.0.0
     */
    protected function getOptions()
    {
        // `disableDescendants` is the single switch for self-parent prevention.
        // It is ONLY valid on a self-referential picker (a category/place
        // choosing its own parent), and there it disables the current node
        // itself AND its descendants (so a node can't be its own parent or pick
        // a child as parent -> cycle). On every other consumer (items, payments,
        // ... assigning categories) it is false, so NOTHING is disabled: those
        // forms don't pick a parent, so there is no node to exclude, and the
        // edited record's id isn't even from this tree.
        $disableDescendants = $this->element['disableDescendants'] == 'true' ? true : false;
        $showPath = $this->element['showPath'] == 'true' ? true : false;
        $orderBy = $this->element['orderBy'] ?? 'name';
        $orderDir = $this->element['orderDir'] ?? 'ASC';
        $currentCategoryIdField = $this->element['currentIdField'] ?? 'id';
        $modelName = $this->element['model'] ?? 'categories';

        $this->options = parent::getOptions();

        $app = Factory::getApplication();

        // Resolved once: a per-option membership test would be one query per category.
        $siteVisible = $app->isClient('site') ? self::visibleCategoryIds() : null;
        $parentOf = [];
        $component = $app->bootComponent('com_alfa');
        $factory = $component->getMVCFactory();
        $model = $factory->createModel($modelName, 'Administrator');

        if (!$model) {
            return $this->options;
        }

        $model->getState('list.ordering');
        $model->setState('filter.state', '*');
        $model->setState('filter.search', '');
        $model->setState('list.limit', '0');
        // Without this the model inherits the REQUEST's limitstart (e.g. a
        // paginated listing's ?start=4) and core getStart() divides by the 0
        // limit above: a 500 on any deep listing page with the filter panel.
        $model->setState('list.start', 0);
        $model->setState('list.ordering', $orderBy);
        $model->setState('list.direction', $orderDir);

        $categories = $model->getItems();
        $currentCategoryId = $disableDescendants
            ? $this->form->getData()->get($currentCategoryIdField)
            : null;

        $disableMode = false;
        $disableParentCategoryLevel = null;

        // Optional subtree scope (catalog filter): when scopeToCurrentCategory is
        // set AND the global category_filter_subtree config is on AND we are
        // browsing inside a category, keep ONLY that category's descendants: a
        // drill-down that lists the current category's subcategories, not the whole
        // tree. Uses the ordered depth walk (no Site CategoryHelper in admin).
        $scopeToCurrent = ((string) ($this->element['scopeToCurrentCategory'] ?? '')) === 'true';
        $scopeCategoryId = ($scopeToCurrent
            && (int) ComponentHelper::getParams('com_alfa')->get('category_filter_subtree', 0) === 1)
            ? (int) Factory::getApplication()->getInput()->getInt('category_id', 0)
            : 0;

        $scopeMode = false;
        $scopeParentLevel = null;

        // id => parent_id for the whole result set, so an ancestor chain can be
        // walked without a query per category.
        foreach ($categories as $category) {
            $parentOf[(int) $category->id] = (int) ($category->parent_id ?? 0);
        }

        foreach ($categories as $category) {
            // Subtree scope: skip everything outside the current category's subtree
            // (and the current category itself; it is the browsing context).
            if ($scopeCategoryId > 0) {
                if ((int) $category->id === $scopeCategoryId) {
                    $scopeMode = true;
                    $scopeParentLevel = $category->depth;

                    continue;
                }

                if ($scopeMode && $category->depth <= $scopeParentLevel) {
                    $scopeMode = false;
                }

                if (!$scopeMode) {
                    continue;
                }
            }

            $disableCurrent = false;

            if ($disableDescendants) {
                // Disable the current node itself (can't be its own parent) ...
                if ($currentCategoryId == $category->id) {
                    $disableCurrent = true;
                    $disableMode = true;
                    $disableParentCategoryLevel = $category->depth;
                }
                // ... and its descendants (can't pick a child as parent -> cycle).
                elseif ($disableMode) {
                    if ($category->depth > $disableParentCategoryLevel) {
                        $disableCurrent = true;
                    } else {
                        // Moved back up or to the same level: stop disabling.
                        $disableMode = false;
                    }
                }
            }

            // On the SITE this field is a catalogue filter, and it is fed by the
            // ADMIN model with filter.state '*', so without this it publishes the
            // name and full ancestor path of every unpublished AND every restricted
            // category, on every listing page, to anyone. The admin form keeps the
            // complete list; only the storefront is narrowed.
            if ($siteVisible !== null && !isset($siteVisible[(int) $category->id])) {
                continue;
            }

            // A visible category under a hidden parent must not name that parent.
            // `path` is the full ancestor chain, so on the site it is used only when
            // every ancestor is itself visible; otherwise the category stands alone.
            $usePath = $showPath;

            if ($usePath && $siteVisible !== null) {
                $ancestor = (int) ($category->parent_id ?? 0);
                $seen = [];

                while ($ancestor > 0 && !isset($seen[$ancestor])) {
                    $seen[$ancestor] = true;

                    if (!isset($siteVisible[$ancestor])) {
                        $usePath = false;

                        break;
                    }

                    $ancestor = (int) ($parentOf[$ancestor] ?? 0);
                }
            }

            $this->options['cat-' . $category->id] = [
                'value' => $category->id,
                'text' => (
                    $usePath
                    ? $category->path
                    : str_repeat('-', $category->depth) . $category->name
                ),
                'disable' => $disableCurrent,
            ];
        }

        return $this->options;
    }

    /**
     * Ids of the published categories the current visitor may see, as a lookup map.
     *
     * @return array<int, true>
     *
     * @since  1.5.0
     */
    private static function visibleCategoryIds(): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true)
            ->select($db->quoteName('a.id'))
            ->from($db->quoteName('#__alfa_categories', 'a'))
            ->where($db->quoteName('a.state') . ' = 1');

        (new CatalogAccessPolicy())->applyToCategories($query, 'a');

        return array_fill_keys(array_map('intval', (array) $db->setQuery($query)->loadColumn()), true);
    }
}
