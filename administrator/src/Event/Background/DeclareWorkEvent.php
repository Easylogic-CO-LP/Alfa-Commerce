<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Background;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Background\JobDeclaration;
use Alfa\Component\Alfa\Administrator\Background\TaskDeclaration;
use Joomla\CMS\Event\AbstractEvent;

/**
 * The background work a plugin declares (onAlfaDeclareWork), addressed to that one plugin, never raised on the shared
 * dispatcher: the plugin's public method of the event's name answers it, and leaves it out of getSubscribedEvents().
 *
 *     public function onAlfaDeclareWork(DeclareWorkEvent $work): void
 *     {
 *         $work->job('stock.check')->label('PLG_ALFA_EXAMPLE_JOB_STOCK_CHECK');
 *     }
 *
 * Core asks it when the plugin is installed, updated, saved or enabled, and keeps a copy; a name is lower-case words
 * joined by dots, at most 64 characters, unique within the plugin.
 *
 * @api HOOK: core calls onAlfaDeclareWork() on the one plugin; nothing is declared without it.
 *
 * @since  2.3.0
 */
final class DeclareWorkEvent extends AbstractEvent
{
    /**
     * The event's name.
     *
     * @since  2.3.0
     */
    public const NAME = 'onAlfaDeclareWork';

    /**
     * What was declared, in order: each name with its declaration.
     *
     * @var list<array{0: string, 1: TaskDeclaration|JobDeclaration}>
     *
     * @since  2.3.0
     */
    private array $declared = [];

    /**
     * @since  2.3.0
     */
    public function __construct()
    {
        parent::__construct(self::NAME, []);
    }

    /**
     * Declare a task: short work done in one go, written with the save that owes it.
     *
     * @param string $name Its name, unique within the plugin (invoice.submit).
     *
     * @since  2.3.0
     */
    public function task(string $name): TaskDeclaration
    {
        $declaration = new TaskDeclaration();
        $this->declared[] = [$name, $declaration];

        return $declaration;
    }

    /**
     * Declare a job: long work done in steps, each step keeping a bookmark.
     *
     * @param string $name Its name, unique within the plugin (catalog.sync).
     *
     * @since  2.3.0
     */
    public function job(string $name): JobDeclaration
    {
        $declaration = new JobDeclaration();
        $this->declared[] = [$name, $declaration];

        return $declaration;
    }

    /**
     * What was declared, in order, for core's reader.
     *
     * @return list<array{0: string, 1: TaskDeclaration|JobDeclaration}>
     *
     * @internal
     *
     * @since  2.3.0
     */
    public function declared(): array
    {
        return $this->declared;
    }
}
