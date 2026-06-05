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
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use DOMCdataSection;
use DOMElement;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;
use Joomla\Registry\Registry;

defined('_JEXEC') or die;

/**
 * Abstract base for alfa-fields plugins.
 *
 * One plugin per field type (plugin name == type name by convention).
 * Default prepareDom() builds a standard <field> DOM node; subclasses override
 * to set type-specific attributes (inputmode, autocomplete, validate, etc.).
 *
 * Joomla 7-ready: implements SubscriberInterface so the dispatcher auto-wires
 * events from getSubscribedEvents(). Subclasses needing extra events (e.g.
 * onBeforeCompileHead) override getSubscribedEvents() — service providers
 * MUST NOT call addListener (deprecated in Joomla 7).
 *
 * Note: prepareDom() is invoked DIRECTLY by FieldsHelper::prepareForm(), not
 * dispatched, so it does NOT belong in getSubscribedEvents().
 */
abstract class FieldsPlugin extends CMSPlugin implements SubscriberInterface
{
    protected $autoloadLanguage = true;

    /**
     * Subscribed events. Base default is empty — subclasses override to add
     * events specific to their plugin. Merge via array_merge with parent
     * to inherit any future base events without redeclaring.
     */
    public static function getSubscribedEvents(): array
    {
        return [];
    }

    /**
     * Build a <field> node for $event->getField() and append it to $event->getFieldset().
     * Subclasses override to tweak; call parent::prepareDom($event) first, then mutate the node.
     */
    public function prepareDom(PrepareDomEvent $event): ?DOMElement
    {
        $field = $event->getField();
        $fieldset = $event->getFieldset();

        if (!FieldsHelper::displayFieldOnForm($field)) {
            return null;
        }

        // Resolve inline {lang: value} maps in the params to the CURRENT language
        // (value/JSON-mode MultilingualText — e.g. choice option labels) so the
        // render, and any option-based subclass reading $field->params, sees plain
        // current-language strings. The admin definition editor uses a different
        // path and keeps the full per-language maps untouched.
        $field->params = json_encode(
            MultilingualHelper::collapseToCurrent(
                is_string($field->params) ? (json_decode($field->params, true) ?: []) : (array) ($field->params ?? []),
            ),
        );

        $params = new Registry($field->params);

        $node = $fieldset->appendChild(new DOMElement('field'));
        $node->setAttribute('name', $field->field_name);
        $node->setAttribute('type', $field->type);
        $node->setAttribute('label', $field->field_label);
        $node->setAttribute('description', $field->field_description ?? '');
        $node->setAttribute('required', $field->required ? 'true' : 'false');

        if (isset($field->default_value) && $field->default_value !== '') {
            $defaultNode = $node->appendChild(new DOMElement('default'));
            $defaultNode->appendChild(new DOMCdataSection((string) $field->default_value));
        }

        // SHOWON: βγάλαμε το 'showon' από εδώ ώστε να ΜΗΝ γίνει Joomla attribute.
        $alwaysForward = ['class', 'labelclass', 'hint', 'layout'];
        foreach ($alwaysForward as $key) {
            $val = $params->get($key, '');
            if ($val !== '' && $val !== null) {
                $node->setAttribute($key, (string) $val);
            }
        }

        // SHOWON: αυτά τα params τα διαχειρίζεται η μηχανή showon — όχι attributes.
        // 'showon_builder' is dead legacy data (old subform) still present in
        // pre-migration rows — never an attribute, always skip it.
        $showonParamKeys = ['showon', 'showon_builder'];

        foreach ($params->toArray() as $key => $param) {
            if (in_array($key, $alwaysForward, true) || in_array($key, $showonParamKeys, true)) {
                continue;
            }

            if (is_array($param)) {
                // Only a non-empty flat list of scalars can become one
                // attribute; anything else (nested/empty) -> drop. Avoids
                // "Array to string conversion" on implode.
                $param = ($param && count($param) === count(array_filter($param, 'is_scalar')))
                    ? implode(',', $param)
                    : '';
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

        // SHOWON: σταθερά attributes που διαβάζει το media/com_alfa/js/showon.js.
        // - showonname/showontype: ΚΑΘΕ πεδίο (για να μπορεί να είναι «διακόπτης»)
        // - showonrule: μόνο αν το πεδίο έχει κανόνα (είναι «λάμπα»)
        $node->setAttribute('showonname', (string) $field->field_name);
        $node->setAttribute('showontype', (string) $field->type);

        $showonRule = (string) $params->get('showon', '');
        if ($showonRule !== '') {
            $node->setAttribute('showonrule', $showonRule);
        }

        return $node;
    }
}
