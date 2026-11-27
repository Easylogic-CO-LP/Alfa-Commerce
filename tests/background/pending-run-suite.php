<?php

/**
 * RUN PENDING ON THE BACKGROUND SCREEN: ONE ACTION OF THE REGISTRY, ONE MESSAGE THAT SAYS WHAT REALLY HAPPENED.
 *
 * What this pins:
 *
 *   P1   the registry offers tasks.pending on the background surface beside the single kinds (tasks.run,
 *        inventory.effects, pricing.groupsets) and the clean-up (tasks.prune): chunked, state changing, core's;
 *   P2   the message of a run carries only the lines that apply: finished with its counts; nothing pending when the
 *        run found nothing and did nothing; stopped at a limit with what is still pending, never "finished"; the
 *        errors when there were any;
 *   P3   the run's counts live in the session from the plan to the last step: zero at the start, added to by each
 *        step, the records done counted for the progress, the steps that only failed counted in a row, forgotten
 *        when the run ends; a run is stopped only by a step without progress or that safety limit, never its length.
 *
 * Nothing of the shop is run: the registry is only read, and the counts are the suite's own. The database is not
 * written.
 *
 * Run from the docroot:
 *
 *   cd <docroot> && php administrator/components/com_alfa/tests/background/pending-run-suite.php
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

PHP_SAPI === 'cli' or exit;

define('_JEXEC', 1);
\defined('JDEBUG') or \define('JDEBUG', false);
define('JPATH_BASE', \dirname(__DIR__, 5));

$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTPS'] = 'on';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';

use Alfa\Component\Alfa\Administrator\Helper\ToolRegistry;
use Alfa\Component\Alfa\Administrator\Plugin\PluginAction;
use Alfa\Component\Alfa\Administrator\Service\Tools\PendingRun;
use Joomla\CMS\Application\AdministratorApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\Session\SessionInterface;

$container = Factory::getContainer();
$container->alias('session', 'session.cli')
    ->alias('JSession', 'session.cli')
    ->alias(Session::class, 'session.cli')
    ->alias(SessionInterface::class, 'session.cli');

$app = $container->get(AdministratorApplication::class);
Factory::$application = $app;
$app->loadLanguage();
$app->getLanguage()->load('com_alfa', JPATH_ADMINISTRATOR);
$app->createExtensionNamespaceMap();

$pass = 0;
$fail = 0;

/**
 * @param string $what What is being asserted, in words.
 * @param mixed  $got  What the code did.
 * @param mixed  $want What it had to do.
 */
$check = static function (string $what, $got, $want) use (&$pass, &$fail): void {
    if ($got === $want) {
        $pass++;
        echo "  ok    {$what}\n";

        return;
    }

    $fail++;
    echo "  FAIL  {$what}\n        got  " . json_encode($got) . "\n        want " . json_encode($want) . "\n";
};

// ── P1: the registry ─────────────────────────────────────────────────────────
echo "P1 the registry\n";

$actions = [];

foreach (ToolRegistry::forSurface('background') as $action) {
    $actions[$action->id] = $action;
}

$check('P1a the combined run and the single kinds are offered on the background surface', array_values(array_intersect(['tasks.pending', 'tasks.run', 'inventory.effects', 'pricing.groupsets', 'tasks.prune'], array_keys($actions))), ['tasks.pending', 'tasks.run', 'inventory.effects', 'pricing.groupsets', 'tasks.prune']);
$pending = $actions['tasks.pending'] ?? new PluginAction();
$check('P1b the combined run is chunked and changes state', [(bool) ($pending->metadata['chunked'] ?? false), $pending->mutates], [true, true]);
$check('P1c it is core\'s, named for staff', [$pending->metadata['owner_group'] ?? null, $pending->label], ['core', 'COM_ALFA_TASKS_RUN_PENDING']);
$check('P1d it comes first in the order of the surface', array_keys($actions)[0] ?? null, 'tasks.pending');

// ── P2: the message ──────────────────────────────────────────────────────────
echo "P2 the message\n";

$finished = Text::_('COM_ALFA_TASKS_PENDING_FINISHED');
$stopped = Text::_('COM_ALFA_TASKS_PENDING_STOPPED');

$check('P2a counts: finished, each kind that did something, nothing else',
    PendingRun::message(counts: ['jobs' => 3, 'stock' => 2, 'prices' => 40, 'errors' => 0], pending: 0, stopped: false),
    $finished . ' ' . Text::sprintf('COM_ALFA_TASKS_PENDING_JOBS', 3) . ' ' . Text::sprintf('COM_ALFA_TASKS_PENDING_STOCK', 2) . ' ' . Text::sprintf('COM_ALFA_TASKS_PENDING_PRICES', 40));
$check('P2b only the kinds that did something are named',
    PendingRun::message(counts: ['jobs' => 0, 'stock' => 5, 'prices' => 0, 'errors' => 0], pending: 0, stopped: false),
    $finished . ' ' . Text::sprintf('COM_ALFA_TASKS_PENDING_STOCK', 5));
$check('P2c nothing pending: that alone',
    PendingRun::message(counts: ['jobs' => 0, 'stock' => 0, 'prices' => 0, 'errors' => 0], pending: 0, stopped: false),
    Text::_('COM_ALFA_TASKS_PENDING_NOTHING'));
$check('P2d a limit reached: stopped, what was done, what is still pending, never finished',
    PendingRun::message(counts: ['jobs' => 12, 'stock' => 0, 'prices' => 0, 'errors' => 0], pending: 7, stopped: true),
    $stopped . ' ' . Text::sprintf('COM_ALFA_TASKS_PENDING_JOBS', 12) . ' ' . Text::sprintf('COM_ALFA_TASKS_PENDING_LEFT', 7));
$check('P2e records still pending without a limit still say stopped, not finished',
    str_starts_with(PendingRun::message(counts: ['jobs' => 1, 'stock' => 0, 'prices' => 0, 'errors' => 0], pending: 2, stopped: true), $stopped), true);
$check('P2f errors are said last',
    PendingRun::message(counts: ['jobs' => 2, 'stock' => 0, 'prices' => 0, 'errors' => 1], pending: 0, stopped: false),
    $finished . ' ' . Text::sprintf('COM_ALFA_TASKS_PENDING_JOBS', 2) . ' ' . Text::sprintf('COM_ALFA_TASKS_PENDING_ERRORS', 1));
$check('P2g a run that only failed is not "nothing pending"',
    PendingRun::message(counts: ['jobs' => 0, 'stock' => 0, 'prices' => 0, 'errors' => 2], pending: 0, stopped: false),
    $finished . ' ' . Text::sprintf('COM_ALFA_TASKS_PENDING_ERRORS', 2));

// ── P3: the counts in the session ────────────────────────────────────────────
echo "P3 the counts\n";

PendingRun::start();
$check('P3a a run starts at zero', PendingRun::counts(), ['jobs' => 0, 'stock' => 0, 'prices' => 0, 'errors' => 0, 'streak' => 0]);
PendingRun::add('jobs', 4);
PendingRun::add('stock', 2);
PendingRun::add('prices', 10);
$check('P3b each step adds to its kind', [PendingRun::counts()['jobs'], PendingRun::counts()['stock'], PendingRun::counts()['prices']], [4, 2, 10]);
$check('P3c the records done are the three kinds together', PendingRun::done(), 16);
$check('P3d steps that only fail are counted in a row, and a step with progress starts the count again', [PendingRun::errorStreak(erredOnly: true), PendingRun::errorStreak(erredOnly: true), PendingRun::errorStreak(erredOnly: false), PendingRun::errorStreak(erredOnly: true)], [1, 2, 0, 1]);
$check('P3e the run ends with its message and forgets its counts', [PendingRun::finish(pending: 0, stopped: false), PendingRun::counts()], [
    $finished . ' ' . Text::sprintf('COM_ALFA_TASKS_PENDING_JOBS', 4) . ' ' . Text::sprintf('COM_ALFA_TASKS_PENDING_STOCK', 2) . ' ' . Text::sprintf('COM_ALFA_TASKS_PENDING_PRICES', 10),
    ['jobs' => 0, 'stock' => 0, 'prices' => 0, 'errors' => 0, 'streak' => 0],
]);
$check('P3f the run stops only at the safety limit of steps that only failed, never for its length', [PendingRun::ERROR_STEPS_MAX, \defined(PendingRun::class . '::STEPS_MAX')], [3, false]);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
