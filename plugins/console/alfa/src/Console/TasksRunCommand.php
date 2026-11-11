<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Plugin\Console\Alfa\Console;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Task\Runner;
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
 * alfa:tasks:run, the server cron's trigger (design 2.7): ticks as the system actor for 25 s or until nothing is due,
 * whichever comes first. A tick that could do nothing (the work is another tick's, or waits for its time) ends the
 * run: the next cron run comes soon. Exit codes: 0 when the work ran or none was due, 1 when the runner refused
 * (maintenance, the update's pause), 2 when it failed.
 *
 * @since  2.2.0
 */
class TasksRunCommand extends AbstractCommand
{
    /**
     * The command's name.
     *
     * @var string
     *
     * @since  2.2.0
     */
    protected static $defaultName = 'alfa:tasks:run';

    /**
     * The command's description.
     *
     * @since  2.2.0
     */
    protected function configure(): void
    {
        Factory::getApplication()->getLanguage()->load(extension: 'plg_console_alfa', basePath: JPATH_ADMINISTRATOR);
        $this->setDescription(description: Text::_(string: 'PLG_CONSOLE_ALFA_TASKS_RUN_DESCRIPTION'));
    }

    /**
     * Tick until the span ends or nothing is due.
     *
     * @param InputInterface $input The input.
     * @param OutputInterface $output The output.
     *
     * @since  2.2.0
     */
    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle(input: $input, output: $output);
        $end = hrtime(true) + Runner::CLI_SECONDS * 1_000_000_000;
        $ran = ['ticks' => 0, 'singles' => 0, 'chunks' => 0];

        try {
            while (($left = intdiv($end - hrtime(true), 1_000_000)) > 0) {
                $tick = Runner::tick(trigger: 'cli', budgetMs: $left);

                if ($tick->refused !== '') {
                    $io->warning(message: Text::_(string: 'PLG_CONSOLE_ALFA_TASKS_RUN_REFUSED_' . strtoupper($tick->refused)));

                    return 1;
                }

                $singles = (int) ($tick->singles['claimed'] ?? 0);
                $ran['ticks']++;
                $ran['singles'] += $singles;
                $ran['chunks'] += $tick->chunks;

                if (($singles === 0 && $tick->chunks === 0) || !Runner::due()) {
                    break;
                }
            }
        } catch (Throwable $e) {
            // The cause goes to the Alfa Commerce log, as the line printed says.
            Tasks::log(entry: 'alfa:tasks:run could not run: ' . $e::class . ': ' . $e->getMessage(), priority: Log::ERROR);
            $io->getErrorStyle()->error(message: Text::sprintf('PLG_CONSOLE_ALFA_TASKS_ERROR', $e::class));

            return 2;
        }

        $io->success(message: Text::sprintf('PLG_CONSOLE_ALFA_TASKS_RUN_DONE', $ran['ticks'], $ran['singles'], $ran['chunks']));

        return 0;
    }
}
