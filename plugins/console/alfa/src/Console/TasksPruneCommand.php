<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Plugin\Console\Alfa\Console;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Maintenance\MaintenanceState;
use Alfa\Component\Alfa\Administrator\Service\Task\Runner;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskRetention;
use Alfa\Component\Alfa\Administrator\Service\Task\Tasks;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\Console\Command\AbstractCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * alfa:tasks:prune: deletes the finished tasks the retention rules let go (TaskRetention), a batch at a time, until
 * nothing is left or 25 seconds passed, and says how many. Failed and held tasks stay until staff act. Exit codes: 0
 * done, 1 during maintenance, 2 when it failed.
 *
 * @since  2.2.0
 */
class TasksPruneCommand extends AbstractCommand
{
    /**
     * The command's name.
     *
     * @var string
     *
     * @since  2.2.0
     */
    protected static $defaultName = 'alfa:tasks:prune';

    /**
     * The command's description.
     *
     * @since  2.2.0
     */
    protected function configure(): void
    {
        Factory::getApplication()->getLanguage()->load(extension: 'plg_console_alfa', basePath: JPATH_ADMINISTRATOR);
        $this->setDescription(description: Text::_(string: 'PLG_CONSOLE_ALFA_TASKS_PRUNE_DESCRIPTION'));
    }

    /**
     * Prune.
     *
     * @param InputInterface $input The input.
     * @param OutputInterface $output The output.
     *
     * @since  2.2.0
     */
    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle(input: $input, output: $output);

        if (MaintenanceState::active()) {
            $io->warning(message: Text::_(string: 'PLG_CONSOLE_ALFA_MAINTENANCE'));

            return 1;
        }

        try {
            $deleted = TaskRetention::prune(budgetMs: Runner::CLI_SECONDS * 1000);
        } catch (Throwable $e) {
            // The cause goes to the Alfa Commerce log, as the line printed says.
            Tasks::log(entry: 'alfa:tasks:prune could not run: ' . $e::class . ': ' . $e->getMessage(), priority: Log::ERROR);
            $io->getErrorStyle()->error(message: Text::sprintf('PLG_CONSOLE_ALFA_TASKS_ERROR', $e::class));

            return 2;
        }

        $io->success(message: Text::plural(string: 'PLG_CONSOLE_ALFA_TASKS_PRUNE_DONE', n: $deleted));

        return 0;
    }
}
