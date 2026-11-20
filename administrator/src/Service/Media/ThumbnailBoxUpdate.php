<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Media;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MediaHelper;
use Alfa\Component\Alfa\Administrator\Service\Setup\ComponentOptions;
use RuntimeException;

/**
 * The thumbnail box an update raises: a site that stores a side of the box below the default
 * (MediaHelper::THUMBNAIL_BOX, 500) has that side written as the default. The update writes the two Options and nothing
 * else: no notification, no job, no thumbnail made again. The thumbnails already made stay as they are; the new ones are
 * made at the new box, and the storefront draws an image from its stored file wherever its thumbnail is smaller than its
 * slot (MediaHelper::srcset()), so a thumbnail of the old box is never stretched. A site that stores no box, or one of
 * 500 or more a side, is left as it is.
 *
 * The installer asks it on an update from an earlier version, where a box a site stored while the default was
 * 200 x 200 was kept by ComponentOptions, so the default of 500 never reached it.
 *
 * @since  2.2.2
 */
final class ThumbnailBoxUpdate
{
    /**
     * The two Options of the box, width then height.
     *
     * @since  2.2.2
     */
    private const SIDES = ['media_thumbnail_width', 'media_thumbnail_height'];

    /**
     * Raise each stored side of the box below the default to the default.
     *
     * @return array{before: array{0: int, 1: int}, after: array{0: int, 1: int}}|null The box before and after; null
     *                                                                                 when nothing was raised.
     *
     * @throws RuntimeException When the Options cannot be written.
     *
     * @since  2.2.2
     */
    public static function raise(): ?array
    {
        $stored = ComponentOptions::stored();
        $before = [];
        $after = [];

        foreach (self::SIDES as $side) {
            // A side stored as no number is one the site does not store (MediaHelper::thumbnailBox()).
            $value = \array_key_exists($side, $stored) && is_numeric($stored[$side]) ? (int) $stored[$side] : MediaHelper::THUMBNAIL_BOX;
            $before[] = $value;
            $after[] = max($value, MediaHelper::THUMBNAIL_BOX);

            if ($value < MediaHelper::THUMBNAIL_BOX) {
                $stored[$side] = MediaHelper::THUMBNAIL_BOX;
            }
        }

        if ($before === $after) {
            return null;
        }

        ComponentOptions::write(params: $stored);

        return ['before' => [$before[0], $before[1]], 'after' => [$after[0], $after[1]]];
    }
}
