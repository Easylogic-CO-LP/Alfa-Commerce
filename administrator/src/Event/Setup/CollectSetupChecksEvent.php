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

use Alfa\Component\Alfa\Administrator\Setup\SetupCheck;
use Joomla\CMS\Event\AbstractEvent;

/**
 * Collects the store-setup checklist (`onAlfaCollectSetupChecks`).
 *
 * Same contract as {@see \Alfa\Component\Alfa\Administrator\Event\Tools\GetToolActionsEvent}:
 * core registers first and through the same door ({@see \Alfa\Component\Alfa\Administrator\Service\Setup\CoreSetupProvider}),
 * then every enabled `alfa` PLATFORM plugin is asked; capability groups
 * (alfa-payments, alfa-search, ...) stay out of shared backend surfaces and are
 * represented by their platform adapter. Ownership is attributed by the collector
 * between contributors, never self-declared, so a check always knows who answers
 * for it.
 *
 * A SETUP CHECK IS A CAPABILITY, NOT A PLUGIN TYPE: a subscriptions plugin wants a
 * "billing schedule configured" row, a marketplace plugin a "vendor policy" row;
 * none of them is a "setup plugin".
 *
 * @since  1.10.0
 */
class CollectSetupChecksEvent extends AbstractEvent
{
    /**
     * The event name every collector dispatches and every contributor implements.
     *
     * @var string
     * @since  1.10.0
     */
    public const NAME = 'onAlfaCollectSetupChecks';

    /**
     * Checks registered so far, in registration order.
     *
     * @var SetupCheck[]
     * @since  1.10.0
     */
    private array $checks = [];

    /**
     * Register one check and hand it back for fluent completion.
     *
     * @param string $id Stable check id, e.g. `payments.active`.
     * @param string $label Language key for the checklist row label.
     *
     *
     * @since  1.10.0
     */
    public function add(string $id, string $label): SetupCheck
    {
        $check = new SetupCheck($id, $label);

        $this->checks[] = $check;

        return $check;
    }

    /**
     * Every valid check registered so far.
     *
     * @return SetupCheck[]
     *
     * @since  1.10.0
     */
    public function getChecks(): array
    {
        return array_values(array_filter(
            $this->checks,
            static fn (SetupCheck $check): bool => $check->isValid(),
        ));
    }

    /**
     * Tag whatever the last contributor added, before asking the next one: ownership
     * is observed by the collector, not claimed by the contributor. Only untagged
     * checks are stamped, so a thrower's half-registered checks stay pinned to the
     * thrower (see ToolRegistry for the failure this prevents).
     *
     * @param string $group Contributor's plugin group (or the reserved core owner).
     * @param string $name Contributor's element name.
     *
     *
     * @since  1.10.0
     */
    public function attributeTo(string $group, string $name): void
    {
        foreach ($this->checks as $check) {
            if (($check->metadata['owner_group'] ?? '') !== '') {
                continue;
            }

            $check->metadata['owner_group'] = $group;
            $check->metadata['owner_name'] = $name;
        }
    }
}
