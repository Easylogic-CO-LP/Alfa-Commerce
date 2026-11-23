<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Background;

\defined('_JEXEC') or die;

/**
 * A job a plugin declares in onAlfaDeclareWork: long work done in steps, each step keeping a bookmark.
 *
 *     $work->job('catalog.sync')
 *         ->label('PLG_ALFA_EINVOICE_JOB_CATALOG_SYNC')
 *         ->covers('PLG_ALFA_EINVOICE_JOB_CATALOG_SYNC_COVERS')
 *         ->callsOtherSystems()
 *         ->every(hours: 6);
 *
 * @api WRITE: the declaration of one job, read when the plugin is installed, updated, saved or enabled.
 *
 * @since  2.3.0
 */
final class JobDeclaration
{
    /**
     * Its name in Tools > Tasks, a key of the plugin's language file.
     *
     * @since  2.3.0
     */
    private string $label = '';

    /**
     * Its Covers text, a key whose %s is the scope; '' for the scope as given.
     *
     * @since  2.3.0
     */
    private string $covers = '';

    /**
     * Whether it shares the one heavy slot.
     *
     * @since  2.3.0
     */
    private bool $heavy = false;

    /**
     * Whether its steps call another system.
     *
     * @since  2.3.0
     */
    private bool $callsOut = false;

    /**
     * The span as given: minutes, hours and days, one of them set.
     *
     * @var array{minutes: int, hours: int, days: int}
     *
     * @since  2.3.0
     */
    private array $every = ['minutes' => 0, 'hours' => 0, 'days' => 0];

    /**
     * Whether a failure shows as danger.
     *
     * @since  2.3.0
     */
    private bool $urgent = false;

    /**
     * Its name in Tools > Tasks, a key of the plugin's own language file. Required.
     *
     * @param string $languageKey The key.
     *
     * @since  2.3.0
     */
    public function label(string $languageKey): self
    {
        $this->label = $languageKey;

        return $this;
    }

    /**
     * Its "Covers" text in Tools > Tasks, a key of the plugin's own language file whose %s is the job's scope.
     * Without it the scope is shown as given, or "The whole shop" for a job with no scope.
     *
     * @param string $languageKey The key.
     *
     * @since  2.3.0
     */
    public function covers(string $languageKey): self
    {
        $this->covers = $languageKey;

        return $this;
    }

    /**
     * It shares the one heavy slot: no other heavy job, core's or another extension's, runs at the same time.
     *
     * @since  2.3.0
     */
    public function heavy(): self
    {
        $this->heavy = true;

        return $this;
    }

    /**
     * Its steps call another system (HTTP, mail): they run outside the runner's transaction, a failed step waits
     * longer between tries (1 min to 24 h), and every call must be safe to repeat.
     *
     * @since  2.3.0
     */
    public function callsOtherSystems(): self
    {
        $this->callsOut = true;

        return $this;
    }

    /**
     * The runner owes the job by itself every so often: when none of it waits or runs and none was created within the
     * span. Exactly one of the three, by name, at least 5 minutes and at most 30 days: every(days: 1). The job is
     * asked with no scope and no data. A fixed time of day is not offered: the runner runs on visits and on cron.
     *
     * @param int $minutes The span in minutes.
     * @param int $hours The span in hours.
     * @param int $days The span in days.
     *
     * @since  2.3.0
     */
    public function every(int $minutes = 0, int $hours = 0, int $days = 0): self
    {
        $this->every = ['minutes' => $minutes, 'hours' => $hours, 'days' => $days];

        return $this;
    }

    /**
     * A failure shows in the attention notice as danger, not as a warning.
     *
     * @since  2.3.0
     */
    public function urgent(): self
    {
        $this->urgent = true;

        return $this;
    }

    /**
     * What was declared, for core's reader of the declaration.
     *
     * @return array{label: string, covers: string, heavy: bool, callsOut: bool, every: array{minutes: int, hours: int, days: int}, urgent: bool}
     *
     * @internal
     *
     * @since  2.3.0
     */
    public function describe(): array
    {
        return [
            'label' => $this->label, 'covers' => $this->covers, 'heavy' => $this->heavy, 'callsOut' => $this->callsOut,
            'every' => $this->every, 'urgent' => $this->urgent,
        ];
    }
}
