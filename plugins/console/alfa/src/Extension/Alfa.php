<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Plugin\Console\Alfa\Extension;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Log\AlfaLog;
use Alfa\Plugin\Console\Alfa\Console\InventoryAuditCommand;
use Alfa\Plugin\Console\Alfa\Console\TasksPruneCommand;
use Alfa\Plugin\Console\Alfa\Console\TasksRunCommand;
use Joomla\Application\ApplicationEvents;
use Joomla\Application\Event\ApplicationEvent;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Console\Application;
use Joomla\Database\DatabaseAwareInterface;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\DatabaseDriver;
use Joomla\Event\SubscriberInterface;
use Throwable;
use UnexpectedValueException;

/**
 * Alfa Commerce's one registration point with the Joomla console: alfa:tasks:run, alfa:tasks:prune and
 * alfa:inventory:audit, and any later alfa:<domain>:<action> command.
 *
 * A thin adapter: each command calls the service Tools, the API, the web cron and the tests call (Runner,
 * TaskRetention, the inventory audit), and no behaviour lives here. The commands are not in
 * Alfa Sync, so the console keeps running the background work while that plugin is disabled. alfa:inventory:audit
 * keeps its name from the inventory console plugin it replaces, so existing server cron lines keep working.
 *
 * @since  2.2.0
 */
final class Alfa extends CMSPlugin implements SubscriberInterface, DatabaseAwareInterface
{
    use DatabaseAwareTrait;

    /**
     * Load the plugin language files automatically.
     *
     * @var bool
     *
     * @since  2.2.0
     */
    protected $autoloadLanguage = true;

    /**
     * Events this plugin subscribes to.
     *
     * @return array<string, string>
     *
     * @since  2.2.0
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ApplicationEvents::BEFORE_EXECUTE => 'registerCommands',
        ];
    }

    /**
     * Add the commands to a console application. A command that cannot be registered is logged and leaves the
     * console running with the others.
     *
     * @param ApplicationEvent $event The event.
     *
     * @since  2.2.0
     */
    public function registerCommands(ApplicationEvent $event): void
    {
        $application = $event->getApplication();

        if (!$application instanceof Application) {
            return;
        }

        foreach ([
            static fn (): object => new TasksRunCommand(),
            static fn (): object => new TasksPruneCommand(),
            function (): object {
                // The audit closes its snapshot through TransactionState, which reads the transaction depth only a
                // driver keeps. Joomla's container hands every extension a driver; anything else is refused here.
                $db = $this->getDatabase();

                if (!$db instanceof DatabaseDriver) {
                    throw new UnexpectedValueException(
                        sprintf('alfa:inventory:audit needs a Joomla database driver, the connection is %s.', get_debug_type($db)),
                    );
                }

                return new InventoryAuditCommand(db: $db);
            },
        ] as $make) {
            try {
                $application->addCommand(command: $make());
            } catch (Throwable $e) {
                AlfaLog::register();
                Log::add(
                    entry: sprintf('plg_console_alfa: a command was not registered: %s', $e->getMessage()),
                    priority: Log::ERROR,
                    category: 'com_alfa',
                );
            }
        }
    }
}
