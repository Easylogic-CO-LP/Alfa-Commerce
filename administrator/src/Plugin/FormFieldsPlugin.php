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
use Alfa\Component\Alfa\Administrator\Helper\RoleRegistry;
use DOMCdataSection;
use DOMElement;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;
use Joomla\Registry\Registry;

defined('_JEXEC') or die;

/**
 * Abstract base for alfa-form-fields plugins.
 *
 * One plugin per field type (plugin name == type name by convention).
 * Default prepareDom() builds a standard <field> DOM node; subclasses override
 * to set type-specific attributes (inputmode, autocomplete, validate, etc.).
 *
 * Joomla 7-ready: implements SubscriberInterface so the dispatcher auto-wires
 * events from getSubscribedEvents(). Subclasses needing extra events (e.g.
 * onBeforeCompileHead) override getSubscribedEvents(); service providers
 * MUST NOT call addListener (deprecated in Joomla 7).
 *
 * Note: prepareDom() is invoked DIRECTLY by FieldsHelper::prepareForm(), not
 * dispatched, so it does NOT belong in getSubscribedEvents().
 *
 * STORING A CANONICAL VALUE: A FILTER, NEVER A RULE. Joomla runs
 * Form::filter() and then Form::validate(), and the model keeps what the
 * FILTER returned; the Registry a rule is handed is Form::validate()'s own
 * copy and is thrown away. A rule that "normalises" by writing into it
 * validates correctly and stores the value exactly as typed. So a field type
 * with one right shape for storage puts a FormFilterInterface on its node:
 *
 *     $node->setAttribute('filter', 'telephone');
 *     FormHelper::addFilterPrefix('Alfa\\Component\\Alfa\\Administrator\\Filter');
 *
 * and lets the rule beside it decide only whether the value is acceptable.
 * The filter never rejects: what it cannot read goes through untouched so the
 * rule can refuse it by name and the person still sees what they typed.
 * {@see \Alfa\Component\Alfa\Administrator\Filter\TelephoneFilter} and
 * plugins/alfa-form-fields/tel are the shipped pair. This is the one path every
 * form takes: the storefront checkout (CartModel::validate), the account's
 * details, the Order screen, so nothing else needs to know the shape.
 *
 * AND THE BROWSER MUST POST THE WHOLE VALUE. A widget that keeps part of it
 * outside the <input> (intl-tel-input holds the country in its flag, the input
 * the national digits) posts only the part the input holds. Rewrite the entry
 * on the form's `formdata` event (it fires for every submission and for every
 * `new FormData(form)`, form.submit() included, where a `submit` listener does
 * not) and skip a disabled input, because set() would put it back into a
 * form that showon took it out of. media/plg_alfa-form-fields_tel/js/tel.js
 * shows the shape. The server filter stays the authority for what is stored.
 *
 * @since  1.0.0
 */
abstract class FormFieldsPlugin extends CMSPlugin implements SubscriberInterface
{
    protected $autoloadLanguage = true;

    /**
     * Subscribed events. Base default is empty; subclasses override to add
     * events specific to their plugin. Merge via array_merge with parent
     * to inherit any future base events without redeclaring.
     * @since  1.0.0
     */
    public static function getSubscribedEvents(): array
    {
        return [];
    }

    /**
     * Build a <field> node for $event->getField() and append it to $event->getFieldset().
     * Subclasses override to tweak; call parent::prepareDom($event) first, then mutate the node.
     * @since  1.0.0
     */
    public function prepareDom(PrepareDomEvent $event): ?DOMElement
    {
        $field = $event->getField();
        $fieldset = $event->getFieldset();
        $context = $event->getContext();

        if (!FieldsHelper::displayFieldOnForm($field, $context, $event->getUserId())) {
            return null;
        }

        // Resolve inline {lang: value} maps in the params to the CURRENT language
        // (value/JSON-mode MultilingualText, e.g. choice option labels) so the
        // render, and any option-based subclass reading $field->params, sees plain
        // current-language strings. The admin definition editor uses a different
        // path and keeps the full per-language maps untouched.
        $field->params = json_encode(
            MultilingualHelper::collapseToCurrent(
                is_string($field->params) ? (json_decode($field->params, true) ?: []) : (array) ($field->params ?? []),
            ),
        );

        $params = new Registry($field->params);

        // A seeded identity field whose translation row is missing (a content
        // language the labels were never written for) still gets a label.
        $label = trim((string) ($field->field_label ?? ''));

        if ($label === '' && RoleRegistry::isRole($field->role ?? null)) {
            $label = RoleRegistry::labelFallback((string) $field->role);
        }

        // Required is a property of the SECTION the form is built for (a phone
        // may be required at checkout and optional at registration); with no
        // context known the field is not demanded.
        $required = $context !== '' && FieldsHelper::fieldRequired($field, $context);

        $node = $fieldset->appendChild(new DOMElement('field'));
        $node->setAttribute('name', $field->field_name);
        $node->setAttribute('type', $field->type);
        $node->setAttribute('label', $label);
        $node->setAttribute('description', $field->field_description ?? '');
        $node->setAttribute('required', $required ? 'true' : 'false');

        if (isset($field->default_value) && $field->default_value !== '') {
            $defaultNode = $node->appendChild(new DOMElement('default'));
            $defaultNode->appendChild(new DOMCdataSection((string) $field->default_value));
        }

        // SHOWON: 'showon' is deliberately NOT forwarded here so it never
        // becomes a native Joomla attribute; the custom showon engine owns it.
        $alwaysForward = ['class', 'labelclass', 'hint', 'layout'];
        foreach ($alwaysForward as $key) {
            $val = $params->get($key, '');
            if ($val !== '' && $val !== null) {
                $node->setAttribute($key, (string) $val);
            }
        }

        // SHOWON: these params belong to the showon engine; never attributes.
        // 'showon_builder' is dead legacy data (old subform) still present in
        // pre-migration rows; never an attribute, always skip it.
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

        // SHOWON: fixed attributes read by media/com_alfa/js/showon.js.
        // - showonname/showontype: EVERY field (so any field can be a trigger)
        // - showonrule: only when the field carries a rule (it is a listener)
        $node->setAttribute('showonname', (string) $field->field_name);
        $node->setAttribute('showontype', (string) $field->type);

        $showonRule = (string) $params->get('showon', '');
        if ($showonRule !== '') {
            $node->setAttribute('showonrule', $showonRule);
        }

        return $node;
    }
}
