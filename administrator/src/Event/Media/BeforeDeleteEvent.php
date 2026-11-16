<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Before-Delete Event (onAlfaMediaBeforeDelete)
 *
 * Cleanup hook fired before the component removes the files of deleted media
 * rows, so a plugin can purge generated derivatives. Notification only: no
 * outputs. Raised on every delete path (2.2.1): Tools > Media, an image removed
 * in a form or the API, and an entity (product, category, manufacturer,
 * variant) deleted with its gallery. A delete made inside a transaction (a
 * save) raises it once that has committed, the rows then already gone from the
 * table, so a save rolled back raises nothing.
 *
 * Extends AbstractImmutableEvent directly (not MediaEvent) because the delete
 * hook has no source/dest/origin/field; it carries the rows and their paths.
 *
 * Input (set by the component, read by the plugin):
 *   $event->getRows()   -> the media rows deleted ({id, path, thumbnail})
 *   $event->getPaths()  -> flattened relative path strings of those rows
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
    //  INPUT: set by the component, read by the plugin (notification only)

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
