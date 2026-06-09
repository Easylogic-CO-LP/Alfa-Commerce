<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Validate Event (onAlfaMediaValidate)
 *
 * Pre-flight validation gate. A plugin may veto a file before any processing
 * happens; the component then skips it and enqueues the error.
 *
 * Input (set by the component, read by the plugin):
 *   $event->getSource()        → absolute source path
 *   $event->getOrigin()        → media origin / context
 *   $event->getField()         → logical field name
 *   $event->getAllowedMimes()  → allowed source MIME types
 *
 * Output (set by the plugin, read by the component):
 *   $event->setValid(false);                 → veto the file
 *   $event->setError('…');                   → message to enqueue
 *   $event->isValid();  $event->getError();  → read back
 *
 * @since  1.0.0
 */

namespace Alfa\Component\Alfa\Administrator\Event\Media;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Pre-flight media validation event.
 *
 * @since  1.0.0
 */
class ValidateEvent extends MediaEvent
{
    // ═════════════════════════════════════════════════════════
    //  INPUT — set by the component, read by the plugin
    // ═════════════════════════════════════════════════════════

    /**
     * Get the allowed source MIME types.
     *
     *
     * @since  1.0.0
     */
    public function getAllowedMimes(): array
    {
        return (array) ($this->arguments['allowedMimes'] ?? []);
    }

    // ═════════════════════════════════════════════════════════
    //  OUTPUT — set by the plugin, read by the component
    // ═════════════════════════════════════════════════════════

    /**
     * Set whether the file is valid. Default is true.
     *
     * Written directly to the arguments store (NOT via setArgument) so the
     * immutability lock is bypassed exactly like ExecuteShipmentActionEvent.
     *
     * @param bool $valid True to allow, false to veto.
     *
     *
     * @since  1.0.0
     */
    public function setValid(bool $valid): void
    {
        $this->arguments['valid'] = $valid;
    }

    /**
     * Whether the file passed validation. Defaults to true when unset.
     *
     *
     * @since  1.0.0
     */
    public function isValid(): bool
    {
        return $this->arguments['valid'] ?? true;
    }

    /**
     * Set the error message to enqueue when the file is vetoed.
     *
     * @param string $error Human-readable error message.
     *
     *
     * @since  1.0.0
     */
    public function setError(string $error): void
    {
        $this->arguments['error'] = $error;
    }

    /**
     * Get the error message set by the plugin.
     *
     *
     * @since  1.0.0
     */
    public function getError(): string
    {
        return (string) ($this->arguments['error'] ?? '');
    }
}
