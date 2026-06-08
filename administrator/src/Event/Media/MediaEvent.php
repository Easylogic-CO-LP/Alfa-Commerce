<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Media Event (abstract base)
 *
 * Shared INPUT getters for every alfa-media event. Each concrete event extends
 * this and adds its own inputs/outputs. Extends AbstractImmutableEvent directly
 * (not GeneralEvent) so no `subject` argument is forced — the media events have
 * no subject.
 *
 * Common input (set by the component, read by the plugin):
 *   $event->getSource()  → absolute source path of the upload
 *   $event->getDest()    → absolute destination path the component proposes
 *   $event->getOrigin()  → media origin / context ('item'|'category'|...)
 *   $event->getField()   → logical field name ('image'|'thumbnail')
 *
 * @since  1.0.2
 */

namespace Alfa\Component\Alfa\Administrator\Event\Media;

use Joomla\CMS\Event\AbstractImmutableEvent;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Abstract base for the alfa-media events.
 *
 * @since  1.0.2
 */
abstract class MediaEvent extends AbstractImmutableEvent
{
    // ═════════════════════════════════════════════════════════
    //  INPUT — set by the component, read by the plugin
    // ═════════════════════════════════════════════════════════

    /**
     * Get the absolute source path of the upload.
     *
     * @return  string
     *
     * @since   1.0.2
     */
    public function getSource(): string
    {
        return (string) ($this->arguments['source'] ?? '');
    }

    /**
     * Get the absolute destination path the component proposes for the file.
     *
     * @return  string
     *
     * @since   1.0.2
     */
    public function getDest(): string
    {
        return (string) ($this->arguments['dest'] ?? '');
    }

    /**
     * Get the media origin / context ('item' | 'category' | 'manufacturer' |
     * 'thumbnail' | ...).
     *
     * @return  string
     *
     * @since   1.0.2
     */
    public function getOrigin(): string
    {
        return (string) ($this->arguments['origin'] ?? '');
    }

    /**
     * Get the logical field name ('image' | 'thumbnail').
     *
     * @return  string
     *
     * @since   1.0.2
     */
    public function getField(): string
    {
        return (string) ($this->arguments['field'] ?? '');
    }
}
