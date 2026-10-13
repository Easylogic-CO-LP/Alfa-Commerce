<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Access;

\defined('_JEXEC') or die;

use Joomla\CMS\Event\AbstractEvent;

/**
 * Collects the ACL actions plugins contribute to com_alfa's Permissions tab.
 *
 * Fired at every alfa* plugin (onAlfaGetAccessActions) by the custom rules field
 * {@see \Alfa\Component\Alfa\Administrator\Field\AlfarulesField}. A plugin declares the
 * permissions its own screens enforce, the same fluent shape as the menu event:
 *
 *   $event->action('alfa.search.manage', 'PLG_..._ACCESS_MANAGE', 'PLG_..._ACCESS_MANAGE_DESC');
 *
 * and the field renders them alongside com_alfa's own access.xml actions, so a store
 * manager can grant or deny them per user group. Every action is then enforced the native
 * way, $user->authorise($name, 'com_alfa'), wherever the plugin (or the shared Panel MVC)
 * checks it.
 *
 * @since  2.0.0
 */
class GetAccessActionsEvent extends AbstractEvent
{
    /**
     * Actions contributed so far.
     *
     * @var object[]
     * @since  2.0.0
     */
    protected array $actions = [];

    /**
     * Contribute an ACL action.
     *
     * @param string $name The action (e.g. 'alfa.search.manage'), checked via authorise().
     * @param string $title A language key for the Permissions tab label.
     * @param string $description A language key for the tooltip; defaults to the title.
     *
     *
     * @since  2.0.0
     */
    public function action(string $name, string $title, string $description = ''): static
    {
        $this->actions[] = (object) [
            'name' => $name,
            'title' => $title,
            'description' => $description !== '' ? $description : $title,
        ];

        return $this;
    }

    /**
     * Every contributed action with a non-empty name.
     *
     * @return object[]
     *
     * @since  2.0.0
     */
    public function all(): array
    {
        return array_values(array_filter(
            $this->actions,
            static fn (object $a): bool => $a->name !== '',
        ));
    }
}
