<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Plugin\Console\Alfainventory\Extension;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Log\AlfaLog;
use Alfa\Plugin\Console\Alfainventory\Console\InventoryAuditCommand;
use Joomla\Application\ApplicationEvents;
use Joomla\Application\Event\ApplicationEvent;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Console\Application;
use Joomla\Database\DatabaseAwareInterface;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;
use Throwable;

/**
 * Registers the Alfa Commerce inventory commands with the Joomla console.
 *
 * The console application imports the console plugin group before it runs, so
 * the commands are added when the application is about to execute. The only
 * command is the read-only inventory consistency audit.
 *
 * @since  1.0.0
 */
final class Alfainventory extends CMSPlugin implements SubscriberInterface, DatabaseAwareInterface
{
    use DatabaseAwareTrait;

    /**
     * Load the plugin language files automatically.
     *
     * @var bool
     *
     * @since  1.0.0
     */
    protected $autoloadLanguage = true;

    /**
     * Events this plugin subscribes to.
     *
     * @return array<string, string>
     *
     * @since  1.0.0
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ApplicationEvents::BEFORE_EXECUTE => 'registerCommands',
        ];
    }

    /**
     * Add the inventory audit command to a console application. A failure is
     * logged and leaves the console running without the command.
     *
     * @param ApplicationEvent $event The before-execute event.
     *
     *
     * @since  1.0.0
     */
    public function registerCommands(ApplicationEvent $event): void
    {
        $application = $event->getApplication();

        if (!$application instanceof Application) {
            return;
        }

        try {
            $application->addCommand(command: new InventoryAuditCommand(db: $this->getDatabase()));
        } catch (Throwable $e) {
            AlfaLog::register();
            Log::add(
                entry: sprintf('plg_console_alfainventory: the inventory audit command was not registered: %s', $e->getMessage()),
                priority: Log::ERROR,
                category: 'com_alfa.inventory',
            );
        }
    }
}
