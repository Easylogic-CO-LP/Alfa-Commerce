<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Before-Process Event (onAlfaMediaBeforeProcess)
 *
 * The full-size image-processing hook. The component performs NO image work
 * itself; the plugin resizes/converts source → dest and reports back whether it
 * processed the file (and, if so, the final path it actually wrote).
 *
 * Input (set by the component, read by the plugin):
 *   $event->getSource()        → absolute source path
 *   $event->getDest()          → absolute destination path the component proposes
 *   $event->getOrigin()        → media origin / context
 *   $event->getField()         → logical field name
 *   $event->getAllowedMimes()  → allowed source MIME types
 *
 * For the MAIN-image path the component carries NO format/quality/dimensions —
 * the plugin reads its own per-context settings. getFormat()/getQuality() and
 * getMaxWidth()/getMaxHeight() therefore return their neutral defaults ('', 80,
 * 0, 0) on this path. Only {@see ThumbnailEvent} populates maxWidth/maxHeight
 * (the component's thumbnail dimensions — the component owns thumbnail SIZE).
 *
 * Output (set by the plugin, read by the component):
 *   $event->setProcessed(true);          → the plugin handled the file
 *   $event->setFinalPath('…');           → the rewritten path it wrote
 *   $event->isProcessed();               → read back (default false)
 *   $event->getFinalPath();              → read back (falls back to getDest())
 *
 * NOTE: `dest` is an INPUT (getDest). The plugin's rewritten path is the
 * SEPARATE output key `finalPath` — never set `dest`.
 *
 * @since  1.0.0
 */

namespace Alfa\Component\Alfa\Administrator\Event\Media;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Full-size media processing event.
 *
 * @since  1.0.0
 */
class BeforeProcessEvent extends MediaEvent
{
    // ═════════════════════════════════════════════════════════
    //  INPUT — set by the component, read by the plugin
    // ═════════════════════════════════════════════════════════

    /**
     * Get the requested output format ('' = keep the original extension).
     *
     *
     * @since  1.0.0
     */
    public function getFormat(): string
    {
        return (string) ($this->arguments['format'] ?? '');
    }

    /**
     * Get the target maximum width in pixels.
     *
     *
     * @since  1.0.0
     */
    public function getMaxWidth(): int
    {
        return (int) ($this->arguments['maxWidth'] ?? 0);
    }

    /**
     * Get the target maximum height in pixels.
     *
     *
     * @since  1.0.0
     */
    public function getMaxHeight(): int
    {
        return (int) ($this->arguments['maxHeight'] ?? 0);
    }

    /**
     * Get the compression strength (1-100).
     *
     *
     * @since  1.0.0
     */
    public function getQuality(): int
    {
        return (int) ($this->arguments['quality'] ?? 80);
    }

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
     * Set whether the plugin processed the file. Default is false (component
     * then stores the source unchanged).
     *
     * Written directly to the arguments store (NOT via setArgument) so the
     * immutability lock is bypassed exactly like ExecuteShipmentActionEvent.
     *
     * @param bool $processed True when the plugin wrote the file.
     *
     *
     * @since  1.0.0
     */
    public function setProcessed(bool $processed): void
    {
        $this->arguments['processed'] = $processed;
    }

    /**
     * Whether a plugin processed the file. Defaults to false when unset.
     *
     *
     * @since  1.0.0
     */
    public function isProcessed(): bool
    {
        return $this->arguments['processed'] ?? false;
    }

    /**
     * Set the final path the plugin actually wrote (when it renamed/converted
     * the file, the resulting path may differ from the proposed dest).
     *
     * This is a DISTINCT output key (`finalPath`) — it never overwrites the
     * `dest` input.
     *
     * @param string $finalPath Absolute path of the written file.
     *
     *
     * @since  1.0.0
     */
    public function setFinalPath(string $finalPath): void
    {
        $this->arguments['finalPath'] = $finalPath;
    }

    /**
     * Get the final written path. Falls back to the proposed dest when the
     * plugin did not rewrite it.
     *
     *
     * @since  1.0.0
     */
    public function getFinalPath(): string
    {
        $finalPath = (string) ($this->arguments['finalPath'] ?? '');

        return $finalPath !== '' ? $finalPath : $this->getDest();
    }
}
