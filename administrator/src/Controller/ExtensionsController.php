<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\ClearData\ClearDataExtensions;
use Alfa\Component\Alfa\Administrator\Service\ClearData\ClearDataStatus;
use Alfa\Component\Alfa\Administrator\Service\Maintenance\MaintenanceAudit;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use RuntimeException;
use Throwable;

/**
 * Tools > Extensions: the three requests of the page (Clear Data, Remove extension only, Remove extension and its
 * data), each held to a POST with the form's token and to the Tools permission (and Try loading again, a link with
 * the token, which only lets the next page load a plugin's code again); the service checks the rest (the
 * permission to clear Alfa data, Joomla's permission to uninstall, the typed phrase) before it does anything. The work
 * is the service's ({@see ClearDataExtensions}); this controller only reads the request and says each row's outcome.
 * Every text that comes from an extension or the system is escaped. The service's refusals (400, 403, 409) are said
 * in its own words; anything else it throws is said as one actionable sentence, its details written to the
 * maintenance log, never an error page.
 *
 * @since  2.1.0
 */
class ExtensionsController extends BaseController
{
    /**
     * Clear Data of the selected extensions.
     *
     * @since  2.1.0
     */
    public function clear(): void
    {
        $this->carry(call: fn (): array => (new ClearDataExtensions())->clearData(user: $this->app->getIdentity(), ids: $this->selected(), phrase: $this->input->post->getString('phrase', '')));
    }

    /**
     * Remove extension only: Joomla uninstalls the selected extensions; their data stays.
     *
     * @since  2.1.0
     */
    public function remove(): void
    {
        $this->carry(call: fn (): array => (new ClearDataExtensions())->remove(user: $this->app->getIdentity(), ids: $this->selected()));
    }

    /**
     * Remove extension and its data: each selected extension clears its data, then Joomla uninstalls it.
     *
     * @since  2.1.0
     */
    public function removeData(): void
    {
        $this->carry(call: fn (): array => (new ClearDataExtensions())->removeWithData(user: $this->app->getIdentity(), ids: $this->selected(), phrase: $this->input->post->getString('phrase', '')));
    }

    /**
     * Try loading again: the next page loads the plugin's code again (a link of a row whose code could not be loaded,
     * with the token in its address). Changes no data.
     *
     * @since  2.1.0
     */
    public function retryLoad(): void
    {
        if (!$this->checkToken('get', false)) {
            $this->setRedirect($this->page(), Text::_('JINVALID_TOKEN_NOTICE'), 'error');

            return;
        }

        try {
            $name = (new ClearDataExtensions())->retryLoad(user: $this->app->getIdentity(), extensionId: $this->input->getInt('id', 0));
        } catch (RuntimeException $e) {
            if (!self::refusal(error: $e)) {
                $this->unexpected(task: 'retryLoad', error: $e);

                return;
            }

            $this->setRedirect($this->page(), self::escape(text: $e->getMessage()), 'error');

            return;
        } catch (Throwable $e) {
            $this->unexpected(task: 'retryLoad', error: $e);

            return;
        }

        $this->setRedirect($this->page(), Text::sprintf('COM_ALFA_EXTENSIONS_RETRY_LOAD_DONE', self::escape(text: $name)), 'notice');
    }

    /**
     * The token of a POST and the Tools permission, then the work, then each row's outcome on the page.
     *
     * @param callable(): list<array{key: string, name: string, status: string, message: string}> $call The work.
     *
     * @throws NotAllowed When the user may not use Tools.
     *
     * @since  2.1.0
     */
    private function carry(callable $call): void
    {
        if (strtoupper($this->input->getMethod()) !== 'POST' || !$this->checkToken('post', false)) {
            $this->setRedirect($this->page(), Text::_('JINVALID_TOKEN_NOTICE'), 'error');

            return;
        }

        if (!$this->app->getIdentity()->authorise('alfa.tools', 'com_alfa')) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        try {
            $results = $call();
        } catch (RuntimeException $e) {
            if (!self::refusal(error: $e)) {
                $this->unexpected(task: $this->getTask(), error: $e);

                return;
            }

            $this->setRedirect($this->page(), self::escape(text: $e->getMessage()), 'error');

            return;
        } catch (Throwable $e) {
            $this->unexpected(task: $this->getTask(), error: $e);

            return;
        }

        foreach ($results as $result) {
            $this->app->enqueueMessage(self::escape(text: $result['message']), match ($result['status']) {
                'cleared', 'removed' => 'success',
                'failed' => 'error',
                default => 'warning',
            });
        }

        $this->setRedirect($this->page());
    }

    /**
     * Whether the service refused the request before doing anything (permission, phrase, selection, a clear at work):
     * a refusal it says in its own words. Anything else it threw is unexpected.
     *
     * @param RuntimeException $error What the service threw.
     *
     * @since  2.1.0
     */
    private static function refusal(RuntimeException $error): bool
    {
        return \in_array($error->getCode(), [400, 403, 409], true);
    }

    /**
     * An unexpected error of a request (a lost lock, a database error, an extension's or the system's failure): its
     * class, message, file and line go to the maintenance log; the page says one actionable sentence.
     *
     * @param string $task The request's task.
     * @param Throwable $error What was thrown.
     *
     * @since  2.1.0
     */
    private function unexpected(string $task, Throwable $error): void
    {
        MaintenanceAudit::detail(run: 'clear-data', line: sprintf(
            'Tools > Extensions (%s) stopped with %s: %s (%s:%d)',
            $task,
            $error::class,
            ClearDataStatus::clean(text: $error->getMessage()),
            $error->getFile(),
            $error->getLine(),
        ));
        $this->setRedirect($this->page(), Text::_('COM_ALFA_EXTENSIONS_ERROR_UNEXPECTED'), 'error');
    }

    /**
     * The selected extension ids.
     *
     * @return list<int>
     *
     * @since  2.1.0
     */
    private function selected(): array
    {
        return array_values(array_map('intval', (array) $this->input->post->get('cid', [], 'int')));
    }

    /**
     * A text from an extension or the system, safe in a message Joomla renders as HTML.
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
     * The Tools > Extensions page.
     *
     * @since  2.1.0
     */
    private function page(): string
    {
        return Route::_('index.php?option=com_alfa&view=extensions', false);
    }
}
