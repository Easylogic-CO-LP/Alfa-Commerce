<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Plugin\AlfaItemFields\Choice\Color;

defined('_JEXEC') or die;

/**
 * Everything this plugin knows about colour, in one place.
 *
 * The colours live in this plugin's own per-value params (`swatch_colors`, declared by
 * params/value.xml), so the rules for reading them belong here too: what counts as a
 * colour, when two spellings are the same colour, and how a list of them is painted.
 *
 * NONE OF THIS IS VISIBLE TO THE COMPONENT. It receives finished group memberships with an
 * already-canonical id and cannot tell a colour from a brand; it is this class that decides
 * `#00F`, `#0000FF` and `#0000ff` are one identity. Keeping that here is what lets a future
 * field type join the same filter with entirely different semantics and no core changes.
 *
 * @since  1.10.0
 */
final class ChoiceColors
{
    /**
     * How many bands a chip may show before it stops being readable.
     *
     * A swatch is roughly 24px across; past six bands it reads as noise rather than as
     * information, so the rest is reported as an overflow count and the full list belongs
     * in the title attribute.
     *
     * @var int
     * @since  1.10.0
     */
    public const MAX_BANDS = 6;

    /**
     * Canonicalise one configured code, or reject it.
     *
     * Accepts `#rgb`, `#rrggbb` and either without the hash (the colour field has stored
     * both over time). Anything else (a name, a gradient, an injection attempt) is not a
     * colour identity and is dropped rather than guessed at.
     *
     * @param string $raw The configured value.
     *
     * @return string|null Canonical `#rrggbb`, or null when this is not a colour.
     *
     * @since  1.10.0
     */
    public static function canonical(string $raw): ?string
    {
        $code = strtolower(trim($raw));

        if ($code === '') {
            return null;
        }

        if ($code[0] !== '#') {
            $code = '#' . $code;
        }

        if (preg_match('/^#[0-9a-f]{3}$/', $code)) {
            $code = '#' . $code[1] . $code[1] . $code[2] . $code[2] . $code[3] . $code[3];
        }

        return preg_match('/^#[0-9a-f]{6}$/', $code) ? $code : null;
    }

    /**
     * The canonical codes on one value, in RENDER ORDER, de-duplicated.
     *
     * Order is what the merchant arranged in the subform and matters to the chip (a
     * Blue/Red swatch is not a Red/Blue one) but never to identity. A repeated code keeps
     * its first position and is dropped after, so a mis-click cannot paint a band twice.
     *
     * @param array $params The value's decoded params.
     *
     * @return array<string, string> Canonical code => the label configured beside it ('' when none).
     *
     * @since  1.10.0
     */
    public static function codes(array $params): array
    {
        $codes = [];

        foreach ((array) ($params['swatch_colors'] ?? []) as $row) {
            $raw = \is_array($row) ? (string) ($row['code'] ?? '') : (string) $row;
            $code = self::canonical($raw);

            if ($code === null || isset($codes[$code])) {
                continue;
            }

            $codes[$code] = \is_array($row) ? trim((string) ($row['label'] ?? '')) : '';
        }

        return $codes;
    }

    /**
     * The CSS background for a list of canonical codes.
     *
     * ONE colour is a flat fill. TWO get a diagonal split, which reads as "this is both"
     * far better than a vertical seam. THREE OR MORE get equal diagonal bands.
     *
     * Hard-stop `linear-gradient` rather than `conic-gradient`: it draws the same clean
     * bands and is supported meaningfully further back (conic needs Chrome 69 / Safari 12.1
     * / Firefox 83). The doubled colour stops are the classic form on purpose: the terser
     * `color start end` syntax is newer than the gradient itself, which would hand back the
     * compatibility this choice was made to gain.
     *
     * Safe to place in a style attribute without escaping: every code has been through
     * {@see self::canonical()}, so the string can only ever be `#` and six hex digits.
     *
     * @param string[] $codes Canonical codes in render order.
     *
     * @return array{style: string, overflow: int}
     *
     * @since  1.10.0
     */
    public static function style(array $codes): array
    {
        $codes = array_values(array_filter(array_map(
            static fn ($c): ?string => self::canonical((string) $c),
            $codes,
        )));

        if (empty($codes)) {
            return ['style' => '', 'overflow' => 0];
        }

        $overflow = max(0, \count($codes) - self::MAX_BANDS);
        $shown = \array_slice($codes, 0, self::MAX_BANDS);

        if (\count($shown) === 1) {
            return ['style' => 'background-color:' . $shown[0], 'overflow' => $overflow];
        }

        $bands = \count($shown);
        $stops = [];

        foreach ($shown as $i => $code) {
            $stops[] = $code . ' ' . round(($i / $bands) * 100, 4) . '%';
            $stops[] = $code . ' ' . round((($i + 1) / $bands) * 100, 4) . '%';
        }

        return [
            'style' => 'background-image:linear-gradient(135deg,' . implode(',', $stops) . ')',
            'overflow' => $overflow,
        ];
    }
}
