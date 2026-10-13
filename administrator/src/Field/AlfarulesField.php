<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Access\GetAccessActionsEvent;
use Alfa\Component\Alfa\Administrator\Menu\MenuBuilder;
use Alfa\Component\Alfa\Administrator\Panel\PanelDispatcher;
use Joomla\CMS\Form\Field\RulesField;
use SimpleXMLElement;
use Throwable;

/**
 * com_alfa's Permissions field: Joomla's rules field, opened to plugins.
 *
 * Joomla's {@see RulesField} already merges any `<action>` children of the field into the
 * permissions it renders. So all this does is ask every alfa* plugin, on
 * {@see GetAccessActionsEvent}, for the ACL actions its own screens enforce, and add them as
 * those children before delegating; no copy of Joomla's rendering, no drift. The result:
 * a plugin's permissions appear in the same Permissions tab as com_alfa's own, granted or
 * denied per user group, and enforced everywhere through native `$user->authorise()`.
 *
 * The rows are drawn in the order the admin menu shows their screens ({@see self::orderByMenu()}).
 *
 * @since  2.0.0
 */
class AlfarulesField extends RulesField
{
    /**
     * @var string
     * @since  2.0.0
     */
    protected $type = 'Alfarules';

    /**
     * Add the plugin-contributed actions to the field element.
     *
     * Done in setup(), NOT in getInput(): the actions have to exist on the
     * element for VALIDATION too, and saving never renders a field.
     * Form::validate() instantiates the field (so setup() runs) and hands the
     * element to `validate="rules"`, whose RulesRule::getFieldActions() accepts
     * only what access.xml declares plus the element's own <action> children.
     * While this ran at render time, every plugin action was drawn as a
     * checkbox, posted back, and then rejected as unknown: the Options screen
     * could not be saved at all ("Invalid field: Permissions") on any site with
     * an alfa* plugin that contributes ACL actions.
     *
     * @param SimpleXMLElement $element The field XML element
     * @param mixed $value The field value
     * @param string $group The field group control name
     *
     * @return bool True on success
     *
     * @since  2.0.0
     */
    public function setup(SimpleXMLElement $element, $value, $group = null)
    {
        $return = parent::setup($element, $value, $group);

        if (!$return) {
            return $return;
        }

        $event = new GetAccessActionsEvent('onAlfaGetAccessActions', []);
        PanelDispatcher::broadcast($event, 'onAlfaGetAccessActions');

        $existing = [];

        foreach ($this->element->children() as $child) {
            if ($child->getName() === 'action') {
                $existing[(string) $child['name']] = true;
            }
        }

        foreach ($event->all() as $action) {
            // The element is shared with the Form's XML tree, so a second setup
            // of the same field would otherwise add every action twice.
            if (isset($existing[$action->name])) {
                continue;
            }

            $child = $this->element->addChild('action');
            $child->addAttribute('name', $action->name);
            $child->addAttribute('title', $action->title);
            $child->addAttribute('description', $action->description);

            $existing[$action->name] = true;
        }

        return $return;
    }

    /**
     * Hand the layout its rows in menu order.
     *
     * Joomla lists access.xml first and plugin actions after it, which put a plugin's
     * permissions below Tools. Ordered by the menu, each screen's permissions sit where the
     * screen does and Tools closes the list, as it closes the menu.
     *
     * @return array
     *
     * @since  2.0.0
     */
    protected function getLayoutData()
    {
        $data = parent::getLayoutData();

        try {
            $data['actions'] = self::orderByMenu(
                actions: array_values((array) ($data['actions'] ?? [])),
                menuOrder: (new MenuBuilder())->accessOrder(),
            );
        } catch (Throwable) {
            // Order is presentation: a menu that cannot be built leaves Joomla's order.
        }

        return $data;
    }

    /**
     * Order permission rows the way the admin menu orders their screens.
     *
     * Joomla's own component rights come first. Then, for each menu entry in menu order, every
     * row of that entry's family: `alfa.items.view` places all `alfa.items.*` rows,
     * `alfa.search.analytics` all `alfa.search.*`, `alfa.tools` itself. Rows no menu entry leads
     * to go just before Tools, which stays last.
     *
     * @param object[] $actions Rows carrying `name`, in Joomla's order.
     * @param string[] $menuOrder Access actions of the menu entries, in menu order.
     *
     * @return object[]
     *
     * @since  2.0.0
     */
    public static function orderByMenu(array $actions, array $menuOrder): array
    {
        $placed = [];
        $taken = [];

        $take = static function (callable $belongs) use ($actions, &$placed, &$taken): void {
            foreach ($actions as $index => $action) {
                if (!isset($taken[$index]) && $belongs((string) $action->name)) {
                    $placed[] = $action;
                    $taken[$index] = true;
                }
            }
        };

        $take(static fn (string $name): bool => str_starts_with($name, 'core.'));

        foreach ($menuOrder as $menuAction) {
            $parts = explode('.', (string) $menuAction);

            if ($parts[0] !== 'alfa' || \count($parts) < 2) {
                continue;
            }

            $family = $parts[0] . '.' . $parts[1];

            $take(static fn (string $name): bool => $name === $family || str_starts_with($name, $family . '.'));
        }

        $rest = [];

        foreach ($actions as $index => $action) {
            if (!isset($taken[$index])) {
                $rest[] = $action;
            }
        }

        $tools = array_search('alfa.tools', array_map(static fn (object $action): string => (string) $action->name, $placed), true);

        if ($tools === false) {
            return array_merge($placed, $rest);
        }

        array_splice($placed, $tools, 0, $rest);

        return $placed;
    }
}
