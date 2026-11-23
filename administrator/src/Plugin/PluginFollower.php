<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Plugin;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Outbound\OutboundConsumers;
use Alfa\Component\Alfa\Administrator\Service\Task\WorkDeclarations;

/**
 * The one place where core follows an alfa plugin's life, called by Alfa Sync from Joomla's extension events: a
 * plugin installed, updated, saved, enabled or disabled has its consumer row of the shop's changes and its declared
 * background work brought in step with it; a plugin no longer installed has both closed. Neither ever breaks the
 * caller: each logs its own failure.
 *
 * @since  2.3.0
 */
final class PluginFollower
{
    /**
     * The plugins among some extensions installed, updated, saved, enabled or disabled: their consumer rows and their
     * declared work in step with them.
     *
     * @param int[] $extensionIds The extensions.
     *
     * @since  2.3.0
     */
    public static function follow(array $extensionIds): void
    {
        OutboundConsumers::followPlugins(extensionIds: $extensionIds);
        WorkDeclarations::followPlugins(extensionIds: $extensionIds);
    }

    /**
     * The plugins among some extensions about to be enabled or disabled (Extensions: Manage announces it before it
     * stores each one): their declared work marked enabled or disabled now; a plugin's declarations are read again
     * when it is next saved, or when its code next asks for a work the copy does not hold.
     *
     * @param int[] $extensionIds The extensions.
     * @param bool $enabled Whether they are being enabled.
     *
     * @since  2.3.0
     */
    public static function switching(array $extensionIds, bool $enabled): void
    {
        WorkDeclarations::markExtensions(extensionIds: $extensionIds, enabled: $enabled);
    }

    /**
     * Plugins uninstalled: the consumer rows of those no longer installed removed with what they were owed, and their
     * declared work closed (waiting work cancelled, failed and held kept for staff).
     *
     * @since  2.3.0
     */
    public static function forgetMissing(): void
    {
        OutboundConsumers::forgetMissingPlugins();
        WorkDeclarations::forgetMissing();
    }
}
