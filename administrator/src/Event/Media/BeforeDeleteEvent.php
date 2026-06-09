<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Before-Delete Event (onAlfaMediaBeforeDelete)
 *
 * Cleanup hook fired before the component removes media rows/files, so a plugin
 * can purge generated derivatives. Notification only — no outputs.
 *
 * Extends AbstractImmutableEvent directly (not MediaEvent) because the delete
 * hook has no source/dest/origin/field — it carries the rows and their paths.
 *
 * Input (set by the component, read by the plugin):
 *   $event->getRows()   → media rows about to be deleted ({path, thumbnail, …})
 *   $event->getPaths()  → flattened relative path strings of those rows
 *
 * @since  1.0.0
 */

namespace Alfa\Component\Alfa\Administrator\Event\Media;

use Joomla\CMS\Event\AbstractImmutableEvent;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Pre-delete cleanup event (no outputs).
 *
 * @since  1.0.0
 */
class BeforeDeleteEvent extends AbstractImmutableEvent
{
    // ═════════════════════════════════════════════════════════
    //  INPUT — set by the component, read by the plugin (notification only)
    // ═════════════════════════════════════════════════════════

    /**
     * Get the media rows about to be deleted.
     *
     *
     * @since  1.0.0
     */
    public function getRows(): array
    {
        return (array) ($this->arguments['rows'] ?? []);
    }

    /**
     * Get the flattened relative path strings of the rows about to be deleted.
     *
     *
     * @since  1.0.0
     */
    public function getPaths(): array
    {
        return (array) ($this->arguments['paths'] ?? []);
    }
}
