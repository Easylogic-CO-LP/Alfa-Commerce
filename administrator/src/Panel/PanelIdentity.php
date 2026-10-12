<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Panel;

\defined('_JEXEC') or die;

/**
 * WHO a panel request is for: the shared admin MVC's discriminator, as a pure value object.
 *
 * A panel is an admin screen an alfa* plugin owns. Every request to the shared Panel
 * controller/model/view carries three reserved params that name which plugin and which
 * of its screens is being served, the same words Joomla identifies a plugin by:
 *
 *   group   -> the plugin GROUP   (the #__extensions folder, e.g. alfa-search)
 *   element -> the plugin ELEMENT (the #__extensions element, e.g. enterprise)
 *   panel   -> the screen id within that plugin (e.g. synonyms)
 *
 * From those every layer derives the same URLs, state keys and layout path. The record
 * primary key stays the plain `id`, so a plugin form field named `id` never collides with
 * routing.
 *
 * This class is deliberately inert: constructing one asserts nothing and touches nothing:
 * no Input, no database, no plugin boot. RESOLUTION (reading the request, enforcing the
 * alfa* boundary, checking the plugin is live) and BOOTING both live in {@see PanelResolver},
 * so a plugin handed an identity on an event can read it and build URLs from it but can never
 * trigger a side effect through it. It is the "vars bag" idea from another shop system made an immutable value.
 *
 * @since  1.7.0
 */
final readonly class PanelIdentity
{
    /**
     * @param string $group Owning plugin group (alfa*).
     * @param string $element Owning plugin element.
     * @param string $panel Screen id within the plugin.
     *
     * @since  1.7.0
     */
    public function __construct(
        public string $group,
        public string $element,
        public string $panel,
    ) {
    }

    /**
     * Composite identity string: group/element:panel.
     *
     *
     * @since  1.7.0
     */
    public function key(): string
    {
        return $this->group . '/' . $this->element . ':' . $this->panel;
    }

    /**
     * The owning plugin's directory: where it ships its panel layouts. A pure path
     * derivation; no filesystem or plugin access.
     *
     *
     * @since  1.7.0
     */
    public function pluginPath(): string
    {
        return JPATH_PLUGINS . '/' . $this->group . '/' . $this->element;
    }

    /**
     * This panel's list URL, so a plugin layout links to itself without knowing the
     * component's routing.
     *
     *
     * @since  1.7.0
     */
    public function listUrl(): string
    {
        return 'index.php?option=com_alfa&view=panel'
            . '&group=' . rawurlencode($this->group)
            . '&element=' . rawurlencode($this->element)
            . '&panel=' . rawurlencode($this->panel);
    }

    /**
     * This panel's edit-screen URL for a record (0 for a new one).
     *
     * @param int $id The record id.
     *
     *
     * @since  1.7.0
     */
    public function editUrl(int $id = 0): string
    {
        return $this->listUrl() . '&screen=edit&id=' . $id;
    }

    /**
     * A user-state key scoped to this exact panel (e.g. for form data kept across a
     * failed save). Owned by the framework; plugins never build these.
     *
     * @param string $suffix A sub-key.
     *
     *
     * @since  1.7.0
     */
    public function stateKey(string $suffix): string
    {
        return 'com_alfa.panel.' . $this->group . '.' . $this->element . '.' . $this->panel . '.' . $suffix;
    }

    /**
     * The hidden fields a plugin's mutation FORM needs to post back to this exact panel:
     * option, task, the three identity params and the CSRF token, so a layout never
     * hardcodes com_alfa's routing. The verb is bare ('save', 'delete', a plugin's own
     * 'toggle', ...); the framework prefixes its shared controller.
     *
     * @param string $verb The task verb (bare; a dotted task is passed through as-is).
     * @param string $token The CSRF token name (framework-provided in the layout's data).
     *
     * @return string A block of hidden <input> elements.
     *
     * @since  1.7.0
     */
    public function mutationFields(string $verb, string $token): string
    {
        $fields = [
            'option' => 'com_alfa',
            'task' => str_contains($verb, '.') ? $verb : 'panel.' . $verb,
            'group' => $this->group,
            'element' => $this->element,
            'panel' => $this->panel,
        ];

        $html = '';

        foreach ($fields as $name => $value) {
            $html .= '<input type="hidden" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
                . '" value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '">';
        }

        if ($token !== '') {
            $html .= '<input type="hidden" name="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '" value="1">';
        }

        return $html;
    }
}
