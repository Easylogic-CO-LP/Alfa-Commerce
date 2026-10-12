<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Module\AlfaFilters\Site\Field;

use Alfa\Module\AlfaFilters\Site\Section\FilterSections;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;

defined('_JEXEC') or die;

/**
 * The panel composer: every section this module can render, in the order it will render
 * them, draggable, each with a show/hide toggle.
 *
 * IT SHOWS AN ANSWER, IT DOES NOT OWN ONE. The order on screen is the RESOLVED order:
 * whatever the item fields' own backend `ordering` produces, with this module's override
 * applied on top ({@see FilterSections::resolve()}). A merchant who has never touched it
 * sees the catalogue's own sequence, and the field stores nothing at all: the input is
 * written back as empty for as long as the arrangement matches the natural one, so
 * backend ordering keeps governing this module forever after.
 *
 * The moment they DO rearrange something, the sequence they arranged is stored, as a
 * preference that is reconciled against the live sections on every render, never as a
 * snapshot that decides what exists. That is what lets a field added next month appear in
 * this panel, in its backend position, with nobody reopening a single module.
 *
 * Grouping is not offered here. Whether a field's values collapse into groups is that
 * field's own setting and is deliberately not repeated per module.
 *
 * @since  1.10.0
 */
class FilterSectionsField extends FormField
{
    /**
     * Field type id: must match the filename and the XML `type` attribute.
     *
     * @var string
     * @since  1.10.0
     */
    protected $type = 'FilterSections';

    /**
     * Build the composer.
     *
     *
     * @since  1.10.0
     */
    protected function getInput(): string
    {
        $fields = FilterSections::filterableFields();
        $natural = FilterSections::natural(array_keys($fields));
        $saved = FilterSections::decode($this->value);

        // order(), NOT resolve(): a hidden section keeps its place in the arrangement and
        // is drawn here with the eye shut. Listing only what renders would quietly move it
        // to the end the moment a merchant switched it back on.
        $rows = FilterSections::order($natural, $saved);

        $this->loadAssets();

        $esc = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $html = [];

        $html[] = '<div class="afx-sections" data-afx-sections'
            . ' data-natural="' . $esc(json_encode($natural)) . '"'
            . ' data-input="' . $esc($this->id) . '">';

        $html[] = '<input type="hidden" name="' . $esc($this->name) . '" id="' . $esc($this->id)
            . '" value="' . $esc($this->value) . '">';

        if (empty($rows)) {
            $html[] = '<p class="afx-sections-empty">' . $esc(Text::_('MOD_ALFAFILTERS_SECTIONS_EMPTY')) . '</p>';
            $html[] = '</div>';

            return implode('', $html);
        }

        $html[] = '<ul class="afx-sections-list">';

        foreach ($rows as $key) {
            $isField = str_starts_with($key, FilterSections::FIELD_PREFIX);
            $isHidden = \in_array($key, $saved['hidden'], true);

            $html[] = '<li class="afx-section' . ($isHidden ? ' is-hidden' : '') . '"'
                . ' data-key="' . $esc($key) . '" draggable="true">';
            $html[] = '<span class="afx-section-grip icon-ellipsis-v" aria-hidden="true"></span>';
            $html[] = '<span class="afx-section-name">' . $esc(FilterSections::label($key, $fields)) . '</span>';
            $html[] = '<span class="afx-section-kind">' . $esc(Text::_(
                $isField ? 'MOD_ALFAFILTERS_SECTIONS_KIND_FIELD' : 'MOD_ALFAFILTERS_SECTIONS_KIND_FIXED',
            )) . '</span>';
            $html[] = '<button type="button" class="afx-section-eye" aria-pressed="' . ($isHidden ? 'false' : 'true') . '"'
                . ' title="' . $esc(Text::_('MOD_ALFAFILTERS_SECTIONS_TOGGLE')) . '">'
                . '<span class="icon-eye' . ($isHidden ? '-slash' : '') . '" aria-hidden="true"></span>'
                . '<span class="visually-hidden">' . $esc(Text::_('MOD_ALFAFILTERS_SECTIONS_TOGGLE')) . '</span>'
                . '</button>';
            $html[] = '</li>';
        }

        $html[] = '</ul>';
        $html[] = '<div class="afx-sections-foot">';
        $html[] = '<button type="button" class="btn btn-sm btn-secondary afx-sections-reset">'
            . $esc(Text::_('MOD_ALFAFILTERS_SECTIONS_RESET')) . '</button>';
        $html[] = '<span class="afx-sections-state" data-afx-state></span>';
        $html[] = '</div>';
        $html[] = '</div>';

        return implode('', $html);
    }

    /**
     * Register this module's asset file and pull in the composer's own style and script.
     *
     * The module edit form is com_modules' document, so nothing of this module's is
     * registered there yet, hence the explicit registry file.
     *
     *
     * @since  1.10.0
     */
    private function loadAssets(): void
    {
        $wa = Factory::getApplication()->getDocument()->getWebAssetManager();
        $wa->getRegistry()->addExtensionRegistryFile('mod_alfa_filters');
        $wa->useStyle('mod_alfa_filters.sections')->useScript('mod_alfa_filters.sections');

        Text::script('MOD_ALFAFILTERS_SECTIONS_NATURAL');
        Text::script('MOD_ALFAFILTERS_SECTIONS_CUSTOM');
    }
}
