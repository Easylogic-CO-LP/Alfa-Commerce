<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Thumbnail Event (onAlfaMediaThumbnail)
 *
 * Same shape as {@see BeforeProcessEvent} — a distinct class/event name so the
 * plugin (and any future listener) can target thumbnail processing separately.
 *
 * Unlike the main-image path, this event DOES carry the component's thumbnail
 * dimensions as maxWidth/maxHeight (getMaxWidth()/getMaxHeight()): the component
 * owns the thumbnail SIZE, the plugin owns the thumbnail FORMAT/QUALITY.
 *
 * Inputs: source, dest, origin, field, maxWidth, maxHeight, allowedMimes.
 * Outputs: setProcessed()/isProcessed() and setFinalPath()/getFinalPath().
 *
 * @since  1.0.0
 */

namespace Alfa\Component\Alfa\Administrator\Event\Media;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Thumbnail processing event.
 *
 * @since  1.0.0
 */
class ThumbnailEvent extends BeforeProcessEvent
{
}
