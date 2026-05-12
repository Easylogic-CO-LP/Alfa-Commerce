<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Joomla\Plugin\AlfaFields\Tel\Extension;

use Alfa\Component\Alfa\Administrator\Event\Fields\PrepareDomEvent;
use Alfa\Component\Alfa\Administrator\Plugin\FieldsPlugin;
use DOMElement;
use Joomla\CMS\Form\FormHelper;
use Joomla\Registry\Registry;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Event\Application\BeforeCompileHeadEvent;

defined('_JEXEC') or die;

final class Tel extends FieldsPlugin
{
    public function onBeforeCompileHead(BeforeCompileHeadEvent $event): void
    {
        $app = $event->getApplication();

        if (!$app->isClient('site')) {
            return;
        }

        $wa = $event->getDocument()->getWebAssetManager();
        $wa->getRegistry()->addRegistryFile('media/plg_alfa-fields_tel/joomla.asset.json');
        $wa->useStyle('plg_alfa-fields_tel.intltelinput')
            ->useStyle('plg_alfa-fields_tel.tel')
            ->useScript('plg_alfa-fields_tel.intltelinput')
            ->useScript('plg_alfa-fields_tel.tel');
    }

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

        $node->setAttribute('type', 'text');

        // Single source of truth for the rule name. Keep it lowercase —
        // camelCase breaks FormHelper::loadClass (splits into sub-namespace).
        if (!$node->getAttribute('validate')) {
            $node->setAttribute('validate', 'alfatel');
        }

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

        FormHelper::addRulePrefix('Joomla\\Plugin\\AlfaFields\\Tel\\Rule');

        return $node;
    }
}
