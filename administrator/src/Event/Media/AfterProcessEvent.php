<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * After-Process Event (onAlfaMediaAfterProcess)
 *
 * Notification hook fired by the component after a successful processing/insert
 * prep. There are NO outputs — a plugin may use it for derivative generation or
 * bookkeeping.
 *
 * Input (set by the component, read by the plugin):
 *   $event->getSource()      → absolute source path
 *   $event->getDest()        → absolute destination path that was stored
 *   $event->getOrigin()      → media origin / context
 *   $event->getField()       → logical field name
 *   $event->getColor()       → dominant colour computed for the stored file
 *   $event->isProcessed()    → whether a plugin actually processed the image
 *
 * @since  1.0.0
 */

namespace Alfa\Component\Alfa\Administrator\Event\Media;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Post-process notification event (no outputs).
 *
 * @since  1.0.0
 */
class AfterProcessEvent extends MediaEvent
{
    // ═════════════════════════════════════════════════════════
    //  INPUT — set by the component, read by the plugin (notification only)
    // ═════════════════════════════════════════════════════════

    /**
     * Get the dominant colour computed for the stored file (e.g. 'rgb(r,g,b)').
     *
     *
     * @since  1.0.0
     */
    public function getColor(): string
    {
        return (string) ($this->arguments['color'] ?? '');
    }

    /**
     * Whether a plugin actually processed the image upstream.
     *
     *
     * @since  1.0.0
     */
    public function isProcessed(): bool
    {
        return (bool) ($this->arguments['processed'] ?? false);
    }
}
