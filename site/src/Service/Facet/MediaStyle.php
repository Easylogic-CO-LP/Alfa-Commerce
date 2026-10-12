<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Facet;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Uri\Uri;

defined('_JEXEC') or die;

/**
 * Turns a stored media value into something safe to put in front of a browser.
 *
 * Generic on purpose: it knows about Joomla media values and about the CSS/HTML contexts
 * they land in, and nothing else. What the image DEPICTS (a colour swatch, a brand logo,
 * a material sample) is the field type's business, and this helper is deliberately unable
 * to tell the difference.
 *
 * There is exactly one implementation because the copies drifted: a regex demanding a
 * leading slash silently rejected every image chosen through Joomla's media picker, which
 * stores `images/x.jpg#joomlaImage://...?width=...`: relative, with an adapter fragment. Three
 * stages, and only the middle one is ours to invent:
 *
 *  1. PARSE with Joomla's own {@see HTMLHelper::cleanImageURL()}. Joomla owns that storage
 *     format and has already changed it once (`?joomla_image_width=` came first), so a
 *     parser of our own would break on the next change.
 *  2. VALIDATE with a whitelist, because cleanImageURL does no safety checking at all and
 *     this lands inside a `style` attribute, where the browser HTML-decodes BEFORE the CSS
 *     parser runs, so escaping alone would let an admin-set value break out of url().
 *  3. RESOLVE against {@see Uri::root()}, because what Joomla stores is relative to the
 *     SITE ROOT while a CSS url() resolves against the current page: on a SEF page like
 *     /en/katastema an `images/x.jpg` would be fetched from /en/images/x.jpg and 404.
 *
 * @since  1.10.0
 */
final class MediaStyle
{
    /**
     * A safe image path: optional scheme, optional leading slash, an image extension. The
     * character class excludes quotes, parentheses, whitespace and colons, which is what
     * keeps the result safe inside url().
     *
     * @var string
     * @since  1.10.0
     */
    private const IMAGE_PATTERN = '#^(?:https?:)?/?[\w./%-]+\.(?:jpe?g|png|gif|webp|svg)$#i';

    /**
     * The `background-image` declaration for a configured swatch image, or ''.
     *
     * Public because every surface that shows a swatch needs it and there must not be a
     * second image parser: the filter module, the item card and the item page all call
     * THIS one. Takes the raw stored value (whatever wrote it) and never inspects any
     * plugin's param shape.
     *
     * @param string $raw The stored media value.
     *
     *
     * @since  1.10.0
     */
    public static function image(string $raw): string
    {
        $url = self::imageUrl($raw);

        return $url === '' ? '' : "background-image:url('" . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . "')";
    }

    /**
     * The resolved, validated URL of a configured swatch image, or ''.
     *
     * Same parse/validate/resolve pipeline as {@see self::image()}, but bare: a card that
     * renders the swatch as an <img> needs the src, not a CSS declaration, and must not
     * grow a second copy of the pipeline to get it.
     *
     * @param string $raw The stored media value.
     *
     *
     * @since  1.10.0
     */
    public static function imageUrl(string $raw): string
    {
        $image = trim($raw);

        if ($image === '') {
            return '';
        }

        // 1. Strip the media adapter fragment / legacy size vars.
        $image = trim((string) HTMLHelper::cleanImageURL($image)->url);

        // 2. Whitelist. The leading slash is OPTIONAL: the media field stores a
        //    site-root-relative path without one, hand-set values usually have one.
        if ($image === '' || !preg_match(self::IMAGE_PATTERN, $image)) {
            return '';
        }

        // Absolute or protocol-relative: someone else's origin, not ours to resolve.
        if (preg_match('#^(?:https?:)?//#', $image)) {
            return $image;
        }

        // 3. Anchor to the site root, and cache-bust local files by mtime: a re-uploaded
        //    swatch keeps its name and browsers cache same-URL images hard.
        $path = '/' . ltrim($image, '/');
        $src = Uri::root(true) . $path;

        if (is_file(JPATH_ROOT . $path)) {
            $src .= '?v=' . filemtime(JPATH_ROOT . $path);
        }

        return $src;
    }
}
