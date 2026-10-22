<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Maintenance\FactoryReset;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use RuntimeException;

/**
 * Tools > Clear everything: the four requests of the page (run, Try again, Continue anyway, Run
 * again), each held to a POST with the form's token and to the permission to clear Alfa data. The work
 * is the domain service's ({@see FactoryReset}); this controller only reads the request and says the
 * outcome. Every text that comes from the database, the system or an extension is escaped.
 *
 * @since  2.1.0
 */
class CleardataController extends BaseController
{
    /**
     * Run Clear everything with the phrase the person typed.
     *
     * @since  2.1.0
     */
    public function run(): void
    {
        $this->carry(call: fn (): array => (new FactoryReset())->run(user: $this->app->getIdentity(), phrase: $this->input->post->getString('phrase', '')));
    }

    /**
     * Try again: call the Clear Data of the extensions that are not cleared again (paused only).
     *
     * @since  2.1.0
     */
    public function retry(): void
    {
        $this->carry(call: fn (): array => (new FactoryReset())->retry(user: $this->app->getIdentity()));
    }

    /**
     * Continue anyway: go on to core's clear with the extension failures recorded (paused only).
     *
     * @since  2.1.0
     */
    public function proceed(): void
    {
        $this->carry(call: fn (): array => (new FactoryReset())->proceed(user: $this->app->getIdentity()));
    }

    /**
     * Run again: core's clear from its start, after a failure or a run that died.
     *
     * @since  2.1.0
     */
    public function resume(): void
    {
        $this->carry(call: fn (): array => (new FactoryReset())->resume(user: $this->app->getIdentity()));
    }

    /**
     * The token and the permission, then the work, then the outcome on the page.
     *
     * @param callable(): array<string, mixed> $call The work.
     *
     * @throws NotAllowed When the user may not clear Alfa data.
     *
     * @since  2.1.0
     */
    private function carry(callable $call): void
    {
        if (!$this->guard()) {
            return;
        }

        try {
            $outcome = $call();
        } catch (RuntimeException $e) {
            $this->setRedirect($this->page(), self::escape(text: $e->getMessage()), 'error');

            return;
        }

        $this->say(outcome: $outcome);
    }

    /**
     * The token of a POST, then the permission.
     *
     * @return bool False when the request was answered already.
     *
     * @throws NotAllowed When the user may not clear Alfa data.
     *
     * @since  2.1.0
     */
    private function guard(): bool
    {
        if (strtoupper($this->input->getMethod()) !== 'POST' || !$this->checkToken('post', false)) {
            $this->setRedirect($this->page(), Text::_('JINVALID_TOKEN_NOTICE'), 'error');

            return false;
        }

        if (!FactoryReset::permitted(user: $this->app->getIdentity())) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        return true;
    }

    /**
     * Say the outcome on the page.
     *
     * @param array<string, mixed> $outcome What FactoryReset returned.
     *
     * @since  2.1.0
     */
    private function say(array $outcome): void
    {
        $phase = (string) ($outcome['phase'] ?? '');

        if ($phase === 'paused') {
            $failed = array_filter((array) ($outcome['extensions'] ?? []), static fn ($e): bool => ($e['status'] ?? '') === 'failed');
            $this->setRedirect($this->page(), Text::sprintf('COM_ALFA_CLEAR_PAUSED', \count($failed)), 'warning');

            return;
        }

        if ($phase !== 'done') {
            $this->setRedirect(
                $this->page(),
                Text::sprintf(
                    'COM_ALFA_CLEAR_FAILED_AT',
                    Text::_('COM_ALFA_CLEAR_STEP_' . strtoupper((string) ($outcome['step'] ?? ''))),
                    self::escape(text: (string) ($outcome['error'] ?? '')),
                ),
                'error',
            );

            return;
        }

        $files = (array) ($outcome['files'] ?? []);
        $this->setRedirect(
            $this->page(),
            Text::sprintf(
                !empty($outcome['clean']) ? 'COM_ALFA_CLEAR_DONE' : 'COM_ALFA_CLEAR_DONE_WARNINGS',
                (int) ($outcome['tables'] ?? 0),
                (int) ($outcome['made'] ?? 0),
                (int) ($files['deleted'] ?? 0),
            ),
            !empty($outcome['clean']) ? 'success' : 'warning',
        );

        foreach ((array) ($outcome['extensions'] ?? []) as $extension) {
            if (\in_array($extension['status'] ?? '', FactoryReset::WARNING_STATUSES, true)) {
                $this->app->enqueueMessage(self::escape(text: FactoryReset::warning(extension: (array) $extension)), 'warning');
            }
        }

        if ((int) ($files['left'] ?? 0) > 0) {
            $this->app->enqueueMessage(Text::sprintf('COM_ALFA_CLEAR_FILES_LEFT', (int) $files['left']), 'notice');
        }

        $images = (array) ($outcome['images'] ?? []);
        $this->app->enqueueMessage(Text::sprintf('COM_ALFA_CLEAR_IMAGES_KEPT', (int) ($images['present'] ?? 0), self::escape(text: (string) ($images['folder'] ?? ''))), 'notice');

        foreach (['store_warning', 'task_warning'] as $warning) {
            if (!empty($outcome[$warning])) {
                $this->app->enqueueMessage(self::escape(text: (string) $outcome[$warning]), 'warning');
            }
        }
    }

    /**
     * A text from the database, the system or an extension, safe in a message Joomla renders as HTML.
     *
     * @param string $text The text.
     *
     * @since  2.1.0
     */
    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    /**
     * The Clear everything page.
     *
     * @since  2.1.0
     */
    private function page(): string
    {
        return Route::_('index.php?option=com_alfa&view=cleardata', false);
    }
}
