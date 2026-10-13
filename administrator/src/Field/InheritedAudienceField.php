<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Catalog\CatalogAccessPolicy;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Throwable;

/**
 * Answers "who can see this product right now?", per dimension.
 *
 * Inheritance is implicit (an empty list means "follow my default category"), which
 * is the right rule but an invisible one: the merchant sees two empty pickers and
 * cannot tell whether the product is public or restricted. This states the EFFECTIVE
 * audience and where it comes from, which is the question actually being asked; "what
 * would I inherit" is only interesting as the answer to it.
 *
 * Both dimensions are always shown, including when there is nothing to inherit. An
 * earlier version collapsed that case into one sentence, which read as though the
 * panel described a single setting and left it ambiguous which list it meant.
 *
 * Read-only by construction. It renders a resolved answer, never an input: the
 * inherited value is not this product's to edit, and offering a control here would
 * imply it could be changed from the wrong place.
 *
 * @since  2.0.0
 */
class InheritedAudienceField extends FormField
{
    /**
     * Field type id: must match the filename and the XML `type`.
     *
     * @var string
     */
    protected $type = 'InheritedAudience';

    /**
     * Render the inherited audience.
     *
     *
     * @since  2.0.0
     */
    protected function getInput(): string
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $policy = new CatalogAccessPolicy(db: $db);

        $categoryId = (int) $this->form->getValue('id_category_default');
        $inherited = $categoryId > 0
            ? $policy->audienceOfCategory($categoryId)
            : ['users' => [], 'groups' => []];

        $category = $categoryId > 0 ? $this->categoryName($categoryId) : '';

        // Read the product's own lists from the FORM, not the database, so the panel
        // describes what the merchant is currently looking at rather than what was
        // last saved.
        $ownUsers = array_values(array_filter((array) $this->form->getValue('allowedUsers')));
        $ownGroups = array_values(array_filter((array) $this->form->getValue('allowedUserGroups')));

        $rows = $this->dimensionRow(
            label:     Text::_('COM_ALFA_FORM_CATEGORY_ALLOWEDUSERS_LBL'),
            own:       $this->userNames($ownUsers),
            inherited: $this->userNames($inherited['users']),
            category:  $category,
        ) . $this->dimensionRow(
            label:     Text::_('COM_ALFA_FORM_CATEGORY_ALLOWEDUSERGROUPS_LBL'),
            own:       $this->groupNames($ownGroups),
            inherited: $this->groupNames($inherited['groups']),
            category:  $category,
        );

        // Only when BOTH dimensions restrict does the conjunction change the answer,
        // and only then is it worth saying. Kept in this one panel rather than split
        // across the two pickers precisely because neither list can state it alone:
        // "Allowed users: Everyone" sitting beside the users picker would read as
        // "everyone can see this" while the group list was quietly excluding them.
        $restricted = ($ownUsers !== [] || $inherited['users'] !== [])
            && ($ownGroups !== [] || $inherited['groups'] !== []);

        $note = $restricted
            ? '<div class="mt-1 small">' . $this->escape(Text::_('COM_ALFA_ITEM_AUDIENCE_BOTH_REQUIRED')) . '</div>'
            : '';

        return '<div class="alert alert-info m-0 py-2"><ul class="mb-0 ps-3">' . $rows . '</ul>' . $note . '</div>';
    }

    /**
     * One dimension: who can see this product on it, and where that comes from.
     *
     * Always rendered, for both dimensions, even when there is nothing to inherit.
     * Collapsing "nothing inherited" into a single sentence read as if the whole
     * panel were one setting, and left the merchant to work out which of the two
     * lists it referred to.
     *
     * @param string $label Dimension label.
     * @param string[] $own Names this product lists itself.
     * @param string[] $inherited Names its default category lists.
     * @param string $category Default category name, '' when there is none.
     *
     *
     * @since  2.0.0
     */
    private function dimensionRow(string $label, array $own, array $inherited, string $category): string
    {
        if ($own !== []) {
            $value = $this->escape(implode(', ', $own));
            $source = Text::_('COM_ALFA_ITEM_AUDIENCE_SOURCE_OWN');
            $class = 'bg-primary';
        } elseif ($inherited !== []) {
            $value = $this->escape(implode(', ', $inherited));
            $source = Text::sprintf('COM_ALFA_ITEM_AUDIENCE_SOURCE_CATEGORY', $this->escape($category));
            $class = 'bg-info';
        } else {
            $value = '<strong>' . $this->escape(Text::_('COM_ALFA_ITEM_AUDIENCE_EVERYONE')) . '</strong>';
            $source = Text::_('COM_ALFA_ITEM_AUDIENCE_SOURCE_NONE');
            $class = 'bg-success';
        }

        return '<li>' . $this->escape($label) . ': ' . $value
            . ' <span class="badge ' . $class . '">' . $source . '</span></li>';
    }

    /**
     * @param int[] $ids User ids.
     *
     * @return string[]
     *
     * @since  2.0.0
     */
    private function userNames(array $ids): array
    {
        return $this->names($ids, '#__users', 'id', 'name');
    }

    /**
     * @param int[] $ids Usergroup ids.
     *
     * @return string[]
     *
     * @since  2.0.0
     */
    private function groupNames(array $ids): array
    {
        return $this->names($ids, '#__usergroups', 'id', 'title');
    }

    /**
     * @param int[] $ids Ids to resolve.
     * @param string $table Source table.
     * @param string $key Key column.
     * @param string $column Label column.
     *
     * @return string[]
     *
     * @since  2.0.0
     */
    private function names(array $ids, string $table, string $key, string $column): array
    {
        if ($ids === []) {
            return [];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true)
            ->select($db->quoteName($column))
            ->from($db->quoteName($table))
            ->whereIn($db->quoteName($key), array_map('intval', $ids));

        return array_map('strval', (array) $db->setQuery($query)->loadColumn());
    }

    /**
     * @param int $categoryId The category id.
     *
     *
     * @since  2.0.0
     */
    private function categoryName(int $categoryId): string
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true)
            ->select($db->quoteName('name'))
            ->from($db->quoteName('#__alfa_categories_' . str_replace('-', '_', strtolower($this->getLanguageTag()))))
            ->where($db->quoteName('id_category') . ' = ' . $categoryId);

        try {
            $name = (string) $db->setQuery($query)->loadResult();
        } catch (Throwable) {
            $name = '';
        }

        return $name !== '' ? $name : ('#' . $categoryId);
    }

    /**
     * @since  2.0.0
     */
    private function getLanguageTag(): string
    {
        return Factory::getApplication()->getLanguage()->getTag();
    }

    /**
     * A single-line note.
     *
     * @param string $html Text whose dynamic parts are ALREADY escaped by the caller.
     * @param string $level Bootstrap contextual class suffix.
     *
     *
     * @since  2.0.0
     */
    private function note(string $html, string $level): string
    {
        // Not escaped here: the caller escapes the interpolated values, and escaping
        // the assembled string again would render "&amp;" to the merchant.
        return '<div class="alert alert-' . $level . ' m-0 py-2">' . $html . '</div>';
    }

    /**
     * @param string $value Raw value.
     *
     *
     * @since  2.0.0
     */
    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
