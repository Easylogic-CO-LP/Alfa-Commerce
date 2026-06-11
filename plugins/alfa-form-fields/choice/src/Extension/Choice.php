<?php

namespace Alfa\Plugin\AlfaFormFields\Choice\Extension;

use Alfa\Component\Alfa\Administrator\Event\Fields\PrepareDomEvent;
use Alfa\Component\Alfa\Administrator\Plugin\FieldsPlugin;
use DOMElement;
use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Event\Application\BeforeCompileHeadEvent;
use Joomla\CMS\Form\FormHelper;
use Joomla\Registry\Registry;

defined('_JEXEC') or die;

final class Choice extends FieldsPlugin
{
    public static function getSubscribedEvents(): array
    {
        return array_merge(parent::getSubscribedEvents(), [
            'onBeforeCompileHead' => 'onBeforeCompileHead',
        ]);
    }

    public function onBeforeCompileHead(BeforeCompileHeadEvent $event): void
    {
        // Guard on the document type, not the client. onBeforeCompileHead is
        // about adding assets to an HTML <head>; if the response is JSON/XML/CLI
        // (no <head>), calling useScript/useStyle is meaningless or errors.
        // This is the same pattern Joomla core uses in its asset-adding listeners.
        $document = $event->getDocument();
        if (!($document instanceof HtmlDocument)) {
            return;
        }

        $wa = $document->getWebAssetManager();
        $wa->getRegistry()->addRegistryFile('media/plg_alfa-form-fields_choice/joomla.asset.json');
        $wa->useStyle('plg_alfa-form-fields_choice.choice')
           ->useScript('plg_alfa-form-fields_choice.choice');
    }

    public function prepareDom(PrepareDomEvent $event): ?DOMElement
    {
        $node = parent::prepareDom($event);
        if ($node === null) {
            return null;
        }

        $field = $event->getField();
        $params = is_string($field->params)
            ? new Registry($field->params)
            : ($field->params ?? new Registry());

        $renderAs = (string) $params->get('render_as', 'select');
        $minSel = (int) $params->get('min_selections', 0);
        $maxSel = (int) $params->get('max_selections', 0);
        $variant = (string) $params->get('button_style', 'solid');
        $layoutMod = (string) $params->get('layout_mode', 'vertical');

        // FieldsPlugin::prepareDom() already copied params onto the node. The key
        // `layout` is a reserved JForm attribute (JLayout id) — if it leaked
        // through it overrides the field class's $layout = 'layouts.button'
        // and FileLayout searches for `vertical.php` (which doesn't exist) → empty render.
        // We renamed our param to `layout_mode`, but strip any stale `layout` attribute as a safety net.
        if ($node->hasAttribute('layout')) {
            $node->removeAttribute('layout');
        }

        // render_as → native or own type + extra attrs.
        // Native modes (list/radio/checkboxes) need no class of ours; Joomla
        // resolves them directly. Custom modes (button/buttonmulti) need the
        // field prefix so FormHelper finds ButtonField / ButtonmultiField.
        [$type, $multiple, $layout] = match ($renderAs) {
            'multiselect' => ['list',         true,  'joomla.form.field.list-fancy-select'],
            'radio' => ['radio',        false, null],
            'checkbox' => ['checkboxes',   false, null],
            'button' => ['button',       false, null],
            'button-multi' => ['buttonmulti',  false, null],
            default => ['list',         false, null],
        };

        $node->setAttribute('type', $type);

        if ($multiple) {
            $node->setAttribute('multiple', 'true');
        }

        if ($layout !== null) {
            $node->setAttribute('layout', $layout);
        }

        // Multi-mode fields get min/max + the choice validator.
        $isMulti = in_array($renderAs, ['multiselect', 'checkbox', 'button-multi'], true);

        if ($isMulti) {
            if ($minSel > 0) {
                $node->setAttribute('data-min-selections', (string) $minSel);
            }
            if ($maxSel > 0) {
                $node->setAttribute('data-max-selections', (string) $maxSel);
            }
            $node->setAttribute('validate', 'choice');
            FormHelper::addRulePrefix('Alfa\\Plugin\\AlfaFormFields\\Choice\\Rule');

            // Joomla's validate.js dispatches handlers via class="validate-X",
            // not the validate="X" attribute (server-side only). Without this
            // class, setHandler('choice') is never invoked. Note: for fieldset
            // render modes (checkbox/button-multi), validate.js still skips
            // handler calls because fieldset.value is undefined — so client min/max
            // for those modes is enforced by choice.js's own submit listener
            // separately. multiselect (a real <select> element) DOES go through
            // the handler chain and benefits from this class.
            $existing = $node->getAttribute('class');
            if (!preg_match('/\bvalidate-choice\b/', $existing)) {
                $node->setAttribute('class', trim($existing . ' validate-choice'));
            }
        }

        // Button variants need a class prefix + custom params forwarded as attrs.
        if (in_array($type, ['button', 'buttonmulti'], true)) {
            FormHelper::addFieldPrefix('Alfa\\Plugin\\AlfaFormFields\\Choice\\Field');
            $node->setAttribute('button_style', $variant);
            $node->setAttribute('layout_mode', $layoutMod);
        }

        // Options pass through as <option> children — JForm picks them up via xpath.
        foreach ((array) $params->get('options', []) as $opt) {
            $value = is_object($opt) ? ($opt->value ?? '') : ($opt['value'] ?? '');
            $text = is_object($opt) ? ($opt->text ?? '') : ($opt['text'] ?? '');

            if ($value === '' && $text === '') {
                continue;
            }

            $optionNode = $node->ownerDocument->createElement('option');
            $optionNode->setAttribute('value', (string) $value);
            $optionNode->appendChild($node->ownerDocument->createTextNode((string) $text));
            $node->appendChild($optionNode);
        }

        return $node;
    }
}
