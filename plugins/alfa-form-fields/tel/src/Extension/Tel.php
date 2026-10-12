<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Plugin\AlfaFormFields\Tel\Extension;

use Alfa\Component\Alfa\Administrator\Event\Fields\PrepareDomEvent;
use Alfa\Component\Alfa\Administrator\Plugin\FormFieldsPlugin;
use DOMElement;
use Joomla\CMS\Form\FormHelper;
use Joomla\Registry\Registry;

defined('_JEXEC') or die;

final class Tel extends FormFieldsPlugin
{
    // Assets (intl-tel-input, tel.js, tel.css) load from inside the layout
    // (tmpl/layouts/tel.php) so they're only pulled in when a tel field actually
    // renders, and a template override of the layout can swap them.

    public function prepareDom(PrepareDomEvent $event): ?DOMElement
    {
        $node = parent::prepareDom($event);

        if ($node === null) {
            return null;
        }

        $field = $event->getField();

        $fieldParams = is_string($field->params)
            ? new Registry($field->params)
            : ($field->params ?? new Registry());

        // Our own field class: wraps the input in a layout that pre-renders
        // translated hint <small> elements that tel.js toggles.
        $node->setAttribute('type', 'tel');
        FormHelper::addFieldPrefix('Alfa\\Plugin\\AlfaFormFields\\Tel\\Field');

        // Single source of truth for the rule name. Keep it lowercase:
        // camelCase breaks FormHelper::loadClass (splits into sub-namespace).
        if (!$node->getAttribute('validate')) {
            $node->setAttribute('validate', 'alfatel');
        }

        // Joomla's validate.js discovers handlers via class="validate-X", NOT
        // the validate="X" attribute (the attribute is server-side only).
        // Without this class, setHandler('alfatel') never fires on submit/blur.
        $validateKey = $node->getAttribute('validate') ?: 'alfatel';
        $existing = $node->getAttribute('class');
        $needle = 'validate-' . $validateKey;
        if (!preg_match('/\b' . preg_quote($needle, '/') . '\b/', $existing)) {
            $node->setAttribute('class', trim($existing . ' ' . $needle));
        }

        // THE FILTER IS WHAT REACHES STORAGE. Joomla runs Form::filter() before
        // Form::validate() and the model returns the FILTERED data; the Registry
        // a rule is handed is a discarded copy. The component's telephone filter
        // reads `default_region` off this node and stores E.164, so whatever
        // dials or posts the number later (a carrier booking a locker) gets
        // one shape. The rule beside it still refuses what cannot be read.
        if (!$node->getAttribute('filter')) {
            $node->setAttribute('filter', 'telephone');
        }

        FormHelper::addFilterPrefix('Alfa\\Component\\Alfa\\Administrator\\Filter');

        if (!$node->getAttribute('inputmode')) {
            $node->setAttribute('inputmode', 'tel');
        }

        if (!$node->getAttribute('autocomplete')) {
            $node->setAttribute('autocomplete', 'tel');
        }

        $defaultRegion = $fieldParams->get('default_region', 'GR');
        $allowedRegions = $fieldParams->get('allowed_regions', '') ?: [];
        $require_mobile = $fieldParams->get('require_mobile', 0);
        $display_format = $fieldParams->get('display_format', 'E164');

        if (is_array($allowedRegions)) {
            $allowedRegions = implode(',', array_filter(array_map('trim', $allowedRegions)));
        } else {
            $allowedRegions = trim((string) $allowedRegions);
        }

        $node->setAttribute('data-default-region', $defaultRegion);
        $node->setAttribute('data-alfa-tel', 'true');
        $node->setAttribute('data-allowed-regions', $allowedRegions);
        $node->setAttribute('data-require-mobile', $require_mobile);
        $node->setAttribute('data-display-format', $display_format);

        FormHelper::addRulePrefix('Alfa\\Plugin\\AlfaFormFields\\Tel\\Rule');

        return $node;
    }
}
