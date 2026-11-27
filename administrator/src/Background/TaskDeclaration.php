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
 * A task a plugin declares in onAlfaDeclareWork: short work done in one go, written with the save that owes it.
 *
 *     $work->task('invoice.submit')->label('PLG_ALFA_EINVOICE_TASK_SUBMIT')->urgent();
 *
 * @api WRITE: the declaration of one task, read when the plugin is installed, updated, saved or enabled.
 *
 * @since  2.3.0
 */
final class TaskDeclaration
{
    /**
     * Its name in Background: a key of the plugin's own language file. Required.
     *
     * @since  2.3.0
     */
    private string $label = '';

    /**
     * Whether a second run duplicates nothing.
     *
     * @since  2.3.0
     */
    private bool $repeatable = false;

    /**
     * Whether a failure shows as danger.
     *
     * @since  2.3.0
     */
    private bool $urgent = false;

    /**
     * Its name in Background, a key of the plugin's own language file. Required.
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
     * Running it twice duplicates nothing: an interrupted run is run again and an unexpected failure is retried.
     * Without it, both are held for staff.
     *
     * @since  2.3.0
     */
    public function repeatable(): self
    {
        $this->repeatable = true;

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
     * @return array{label: string, repeatable: bool, urgent: bool}
     *
     * @internal
     *
     * @since  2.3.0
     */
    public function describe(): array
    {
        return ['label' => $this->label, 'repeatable' => $this->repeatable, 'urgent' => $this->urgent];
    }
}
