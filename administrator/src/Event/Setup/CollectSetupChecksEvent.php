<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Setup;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Plugin\PluginId;
use Alfa\Component\Alfa\Administrator\Setup\SetupCheck;
use InvalidArgumentException;
use Joomla\CMS\Event\AbstractEvent;

/**
 * Collects the store-setup checklist (`onAlfaCollectSetupChecks`).
 *
 * Core's checks are in the event first, through its constructor ({@see \Alfa\Component\Alfa\Administrator\Service\Setup\CoreSetupProvider::checks()}),
 * then every enabled `alfa` PLATFORM plugin is asked; capability groups
 * (alfa-payments, alfa-search, ...) stay out of shared backend surfaces and are
 * represented by their platform adapter. Each plugin names itself as the check's
 * contributor (attribution, never an authorisation), so a check always knows who
 * answers for it.
 *
 * A SETUP CHECK IS A CAPABILITY, NOT A PLUGIN TYPE: a subscriptions plugin wants a
 * "billing schedule configured" row, a marketplace plugin a "vendor policy" row;
 * none of them is a "setup plugin".
 *
 * @since  2.0.0
 */
class CollectSetupChecksEvent extends AbstractEvent
{
    /**
     * The event name every collector dispatches and every contributor implements.
     *
     * @var string
     * @since  2.0.0
     */
    public const NAME = 'onAlfaCollectSetupChecks';

    /**
     * Checks registered so far, in registration order.
     *
     * @var SetupCheck[]
     * @since  2.0.0
     */
    private array $checks = [];

    /**
     * The event, holding the component's own checks (the coreChecks argument: SetupCheck[], each owned by
     * core.com_alfa) before any plugin is asked, as the menu's core entries enter it (2.2.0); every later check comes
     * through add(), under its contributor.
     *
     * @param string $name The event name.
     * @param array $arguments The arguments; coreChecks is taken here and not kept as an argument.
     *
     * @throws InvalidArgumentException When a core check is not a SetupCheck.
     *
     * @since  2.2.0
     */
    public function __construct(string $name, array $arguments = [])
    {
        $coreChecks = $arguments['coreChecks'] ?? [];
        unset($arguments['coreChecks']);

        parent::__construct($name, $arguments);

        foreach ($coreChecks as $check) {
            if (!$check instanceof SetupCheck) {
                throw new InvalidArgumentException('A core setup check is a SetupCheck.');
            }

            $this->checks[] = $check;
        }
    }

    /**
     * Register one check, owned by the plugin that names itself, and hand it back for fluent completion. The
     * contributor is required: core's own checks enter through the constructor.
     *
     * @param string $contributor The contributing plugin, group.element (2.2.0; attribution, never an authorisation).
     * @param string $id Stable check id, e.g. `payments.active`.
     * @param string $label Language key for the checklist row label.
     *
     * @throws InvalidArgumentException When the contributor is not group.element.
     *
     * @since  2.0.0
     */
    public function add(string $contributor, string $id, string $label): SetupCheck
    {
        $owner = PluginId::of($contributor);
        $check = new SetupCheck($id, $label);
        $check->metadata['owner_group'] = $owner->group;
        $check->metadata['owner_name'] = $owner->element;

        $this->checks[] = $check;

        return $check;
    }

    /**
     * Every valid check registered so far.
     *
     * @return SetupCheck[]
     *
     * @since  2.0.0
     */
    public function getChecks(): array
    {
        return array_values(array_filter(
            $this->checks,
            static fn (SetupCheck $check): bool => $check->isValid(),
        ));
    }
}
