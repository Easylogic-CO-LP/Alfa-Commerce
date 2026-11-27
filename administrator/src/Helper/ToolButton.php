<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Plugin\PluginAction;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;

/**
 * The attributes a button that runs a registered tool action carries, read by the Tools script: the one set, whether
 * the button sits in a Tools card or on the Background screen's toolbar, so an action behaves the same wherever its
 * button is.
 *
 * An action flagged chunked runs the plan and chunk loop (toolaction.plan and toolaction.chunk), any other the single
 * request (toolaction.run); one that changes state is sent as a POST carrying a one-time key, which the endpoint
 * demands of it. The owner travels in the address as a hint only: the server asks that provider whether it really
 * declares the action, enabled, before running it. The button names the element that shows its progress and its
 * outcome (data-progress-target, an id), so the script never guesses where that is; that attribute is also how the
 * script finds the buttons it drives, so a button's classes stay free for how it looks.
 *
 * @since  2.3.0
 */
final class ToolButton
{
    /**
     * The address every tool request starts from: the component, with the form token.
     *
     * @since  2.3.0
     */
    public static function ajaxBase(): string
    {
        return 'index.php?option=com_alfa&' . Session::getFormToken() . '=1';
    }

    /**
     * The attributes of an action's button, name => value, the values unescaped.
     *
     * @param PluginAction $action The action.
     * @param string $progress The id of the element that shows the button's progress and outcome.
     *
     * @return array<string, string>
     *
     * @since  2.3.0
     */
    public static function attributes(PluginAction $action, string $progress): array
    {
        $base = self::ajaxBase();
        $owner = ($action->metadata['owner_group'] ?? '') . '/' . ($action->metadata['owner_name'] ?? '');
        $params = '&action=' . rawurlencode($action->id) . '&owner=' . rawurlencode($owner);
        $attributes = ['type' => 'button', 'data-progress-target' => $progress];

        if ((bool) ($action->metadata['chunked'] ?? false)) {
            $attributes['data-action'] = 'backfill';
            $attributes['data-plan-url'] = $base . '&task=toolaction.plan' . $params;
            $attributes['data-chunk-url'] = $base . '&task=toolaction.chunk' . $params;
        } else {
            $attributes['data-action'] = 'single';
            $attributes['data-url'] = $base . '&task=toolaction.run' . $params;
        }

        if ($action->mutates) {
            $attributes['data-mutating'] = '1';
        }

        if ($action->requires_confirmation) {
            $attributes['data-confirm-message'] = Text::_($action->confirmation_message ?? 'JGLOBAL_CONFIRM_DELETE');
        }

        if ($action->tooltip !== null) {
            $attributes['title'] = Text::_($action->tooltip);
        }

        if (!$action->enabled) {
            $attributes['disabled'] = 'disabled';
        }

        return $attributes;
    }

    /**
     * The attributes as HTML, each value escaped for an attribute.
     *
     * @param array<string, string> $attributes The attributes, name => value.
     *
     * @since  2.3.0
     */
    public static function html(array $attributes): string
    {
        $html = '';

        foreach ($attributes as $name => $value) {
            $html .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"';
        }

        return $html;
    }

    /**
     * What the button says: the action's button label when it has one of its own, else its title.
     *
     * @param PluginAction $action The action.
     *
     * @since  2.3.0
     */
    public static function label(PluginAction $action): string
    {
        return isset($action->metadata['button_label']) ? Text::_((string) $action->metadata['button_label']) : Text::_($action->label);
    }
}
