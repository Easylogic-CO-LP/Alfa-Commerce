<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Plugin\AlfaFormFields\Text\Extension;

use Alfa\Component\Alfa\Administrator\Event\Fields\PrepareDomEvent;
use Alfa\Component\Alfa\Administrator\Plugin\FormFieldsPlugin;
use DOMElement;
use Joomla\Registry\Registry;

defined('_JEXEC') or die;

/**
 * Single-line text field. The `validation` param turns it into an email or
 * URL input: Joomla's EmailField / UrlField render a plain <input> of that
 * type, and the matching rule (EmailRule / UrlRule) validates on the server:
 * a format is a RULE on a text field, not a field type of its own.
 *
 * @since  1.0.0
 */
final class Text extends FormFieldsPlugin
{
    /** Format -> Joomla field type, server rule, input mode and autocomplete. */
    private const FORMATS = [
        'email' => ['type' => 'email', 'validate' => 'email', 'inputmode' => 'email', 'autocomplete' => 'email'],
        'url' => ['type' => 'url', 'validate' => 'url', 'inputmode' => 'url', 'autocomplete' => 'url'],
    ];

    /**
     * Build the node, then apply the chosen format.
     *
     * @param PrepareDomEvent $event The prepare-DOM event.
     *
     *
     * @since  2.0.0
     */
    public function prepareDom(PrepareDomEvent $event): ?DOMElement
    {
        $node = parent::prepareDom($event);

        if ($node === null) {
            return null;
        }

        $field = $event->getField();
        $params = is_string($field->params) ? new Registry($field->params) : new Registry((array) ($field->params ?? []));
        $format = self::FORMATS[(string) $params->get('validation', '')] ?? null;

        // The param is forwarded as a plain attribute by the base class; it is
        // meaningless on the Joomla field, so it is replaced by what it means.
        $node->removeAttribute('validation');

        if ($format === null) {
            return $node;
        }

        $node->setAttribute('type', $format['type']);

        if (!$node->getAttribute('validate')) {
            $node->setAttribute('validate', $format['validate']);
        }

        if (!$node->getAttribute('inputmode')) {
            $node->setAttribute('inputmode', $format['inputmode']);
        }

        if (!$node->getAttribute('autocomplete')) {
            $node->setAttribute('autocomplete', $format['autocomplete']);
        }

        // Joomla's validate.js and the Alfa validate engine both discover a
        // handler by class="validate-<rule>" (the attribute is server-side only).
        $needle = 'validate-' . $format['validate'];
        $class = (string) $node->getAttribute('class');

        if (!preg_match('/\b' . preg_quote($needle, '/') . '\b/', $class)) {
            $node->setAttribute('class', trim($class . ' ' . $needle));
        }

        return $node;
    }
}
