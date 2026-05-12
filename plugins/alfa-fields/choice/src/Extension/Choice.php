<?php

namespace Joomla\Plugin\AlfaFields\Choice\Extension;

use Alfa\Component\Alfa\Administrator\Event\Fields\PrepareDomEvent;
use Alfa\Component\Alfa\Administrator\Plugin\FieldsPlugin;
use DOMElement;
use Joomla\CMS\Event\Application\BeforeCompileHeadEvent;
use Joomla\CMS\Form\FormHelper;
use Joomla\Registry\Registry;

defined('_JEXEC') or die;

final class Choice extends FieldsPlugin
{
    public function onBeforeCompileHead(BeforeCompileHeadEvent $event): void
    {
        if (!$event->getApplication()->isClient('site')) {
            return;
        }

        $wa = $event->getDocument()->getWebAssetManager();
        $wa->getRegistry()->addRegistryFile('media/plg_alfa-fields_choice/joomla.asset.json');
        $wa->useStyle('plg_alfa-fields_choice.choice')
           ->useScript('plg_alfa-fields_choice.choice');
    }

    public function prepareDom(PrepareDomEvent $event): ?DOMElement
    {
        $node = parent::prepareDom($event);
        if ($node === null) {
            return null;
        }

        FormHelper::addFieldPrefix('Joomla\\Plugin\\AlfaFields\\Choice\\Field');
        $node->setAttribute('type', 'alfachoice');

        $field = $event->getField();
        $params = is_string($field->params)
            ? new Registry($field->params)
            : ($field->params ?? new Registry());

        //        echo '<pre>';
        //        print_r($params);
        //        echo '</pre>';
        //
        //        exit;

        // Append options as <option> children — JForm/Field reads them via xpath('option').
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
