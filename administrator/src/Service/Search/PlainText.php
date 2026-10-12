<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Search;

defined('_JEXEC') or die;

/**
 * Reduces merchant-authored HTML to the words inside it.
 *
 * Exists so the canonical document carries TEXT and never markup. Descriptions are
 * written in an editor, so the stored value is HTML; an engine that indexed it raw
 * would match on tag and attribute names, and a shopper searching "style" would be
 * answered by every product with a styled paragraph.
 *
 * Extraction happens ONCE, at document build time, rather than in each engine's
 * projection. Three engines stripping the same markup three different ways is three
 * different corpora claiming to describe one catalogue.
 *
 * LOSSY BY DESIGN. Structure, emphasis and links are discarded and cannot be
 * recovered: this output is for matching, never for display. The rendering path
 * reads the original column and is untouched by anything here.
 *
 * @since  1.5.0
 */
final class PlainText
{
    /**
     * Tags whose CONTENT is not prose and must leave with them.
     *
     * `strip_tags()` removes the tag and keeps what is between it, so on a stored
     * `<script>var x = 1;</script>` it yields the script body as searchable text.
     * These are cut whole, before any stripping.
     *
     * @var string[]
     *
     * @since  1.5.0
     */
    private const VOID_CONTENT_TAGS = ['script', 'style', 'noscript', 'iframe', 'template'];

    /**
     * Extract the readable words from an HTML fragment.
     *
     * @param string $html Raw editor output. Plain text is accepted and passes through.
     * @param int $maxChars Hard cap on the result, 0 for unbounded. Applied last, on
     *                      character count rather than bytes, so a multibyte sequence
     *                      is never split into an invalid one.
     *
     * @return string Whitespace-collapsed, trimmed plain text; '' when nothing readable remains.
     *
     * @since  1.5.0
     */
    public static function fromHtml(string $html, int $maxChars = 0): string
    {
        if (trim($html) === '') {
            return '';
        }

        // Cut non-prose elements together with their contents.
        $text = preg_replace(
            '#<(' . implode('|', self::VOID_CONTENT_TAGS) . ')\b[^>]*>.*?</\1\s*>#isu',
            ' ',
            $html,
        ) ?? $html;

        // Unclosed variants of the same elements would otherwise leak their bodies.
        $text = preg_replace(
            '#<(' . implode('|', self::VOID_CONTENT_TAGS) . ')\b[^>]*>.*$#isu',
            ' ',
            $text,
        ) ?? $text;

        // A separator before stripping, or "Buy now" and "Add to cart" weld into one token.
        $text = preg_replace('#<[^>]+>#u', ' ', $text) ?? $text;

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Non-breaking space survives decoding as U+00A0 and is not matched by \s in
        // every PCRE build; fold it explicitly before collapsing.
        $text = str_replace("\xC2\xA0", ' ', $text);
        $text = self::collapse($text);

        if ($maxChars > 0 && self::length($text) > $maxChars) {
            $text = rtrim(self::cut($text, $maxChars));
        }

        return $text;
    }

    /**
     * Collapse every run of whitespace to one space and trim.
     *
     * Text hygiene, not a search decision, which is why it lives here and not in a
     * projection or a normaliser. Three byte-identical copies of this expression
     * existed across two ownership boundaries, so a change to what counts as
     * whitespace would have had to be remembered in a class an engine author owns.
     *
     * @param string $text Subject.
     *
     *
     * @since  1.5.0
     */
    public static function collapse(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * Lowercase, using the multibyte implementation when the extension is present.
     *
     * The fallback matters: without mbstring, `strtolower` leaves accented and Greek
     * capitals untouched, so an indexer and a query normaliser that disagree about
     * which one to call will index "ÉTÉ" and search for "été".
     *
     * @param string $text Subject.
     *
     *
     * @since  1.5.0
     */
    public static function lower(string $text): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
    }

    /**
     * @param string $text Subject.
     *
     * @return int Character count, falling back to byte count without mbstring.
     *
     * @since  1.5.0
     */
    private static function length(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }

    /**
     * @param string $text Subject.
     * @param int $length Characters to keep.
     *
     *
     * @since  1.5.0
     */
    private static function cut(string $text, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($text, 0, $length, 'UTF-8') : substr($text, 0, $length);
    }
}
