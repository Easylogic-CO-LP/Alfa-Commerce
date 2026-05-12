<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Plugin;

use Alfa\Component\Alfa\Administrator\Event\Fields\PrepareDomEvent;
use Alfa\Component\Alfa\Administrator\Helper\FieldsHelper;
use DOMCdataSection;
use DOMElement;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Registry\Registry;

defined('_JEXEC') or die;

/**
 * Abstract base for alfa-fields plugins.
 *
 * One plugin per field type (plugin name == type name by convention).
 * Default prepareDom() builds a standard <field> DOM node; subclasses override
 * to set type-specific attributes (inputmode, autocomplete, validate, etc.).
 */
abstract class FieldsPlugin extends CMSPlugin
{
    protected $autoloadLanguage = true;

    /**
     * Build a <field> node for $event->getField() and append it to $event->getFieldset().
     * Subclasses override to tweak; call parent::prepareDom($event) first, then mutate the node.
     */
    public function prepareDom(PrepareDomEvent $event): ?DOMElement
    {
        $field    = $event->getField();
        $fieldset = $event->getFieldset();

        if (!FieldsHelper::displayFieldOnForm($field)) {
            return null;
        }

//        $params = clone $this->params;

        $params = new Registry($field->params);

        $node = $fieldset->appendChild(new DOMElement('field'));
        $node->setAttribute('name',        $field->field_name);
        $node->setAttribute('type',        $field->type);
        $node->setAttribute('label',       $field->field_label);
        $node->setAttribute('description', $field->field_description ?? '');
        $node->setAttribute('required',    $field->required ? 'true' : 'false');

        if (isset($field->default_value) && $field->default_value !== '') {
            $defaultNode = $node->appendChild(new DOMElement('default'));
            $defaultNode->appendChild(new DOMCdataSection((string) $field->default_value));
        }

        $alwaysForward = ['class', 'labelclass', 'hint', 'layout', 'showon'];
        foreach ($alwaysForward as $key) {
            $val = $params->get($key, '');
            if ($val !== '' && $val !== null) {
                $node->setAttribute($key, (string) $val);
            }
        }

        foreach ($params->toArray() as $key => $param) {
            if (in_array($key, $alwaysForward, true)) {
                continue;
            }

            if (is_array($param)) {
                // Multi-dim arrays can't be expressed as a single attribute.
                $param = count($param) === count($param, COUNT_RECURSIVE) ? implode(',', $param) : '';
            }

            if ($param === '' || $param === null || (!is_string($param) && !is_numeric($param))) {
                continue;
            }

            $node->setAttribute($key, (string) $param);
        }

        if (!FieldsHelper::canEditFieldValue($field)) {
            $node->setAttribute('disabled', 'true');
            $node->setAttribute('readonly', 'true');
        }

        return $node;
    }
}
