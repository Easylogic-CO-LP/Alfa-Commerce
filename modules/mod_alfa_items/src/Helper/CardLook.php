<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Module\AlfaItems\Site\Helper;

\defined('_JEXEC') or die;

/**
 * The look of the module's product card: its style (standard, or the image filling the
 * card with the name and buy form over it), where the image sits and how large it may
 * be, how it fits its frame and the frame's shape, which part of a trimmed image stays
 * in view, the name's lines and weight, the padding, the border, the corners and the
 * shadows.
 *
 * With them, since they are set the same way (per level and per breakpoint), travel
 * the settings of what the cards sit in: whether that is a grid or a slider, the grid's
 * columns and gap, the slider's cards per view, spacing, autoplay, loop, arrows and
 * dots. A few of those have a default of their own on every screen (PER_DEVICE): a
 * narrow screen shows fewer cards whatever a wider one was told.
 *
 * Two levels set it, the module (its Style tab) and a tab, and each level
 * sets it for every screen and, where it wants, again for a breakpoint (the module's
 * two widths: tablet and mobile). A level is stored as `{"base": {...}, "bp": {"<breakpoint key>": {...}}}`
 * holding only what it sets. On a device a level's own values cascade from every screen
 * down through the wider breakpoints to that one; then the levels are laid over each
 * other, the more specific one winning whatever it sets there.
 *
 * Nothing of the result travels on the card but the name of its place
 * (`data-look="<module>-<tab>"`): css() writes the rules for every place of a
 * module instance (what differs from the stylesheet on every screen, then what
 * changes at each breakpoint) and the page carries them once, so a tab fetched later,
 * or a card drawn again for another variant, finds its rules waiting.
 *
 * @since  1.0.0
 */
final class CardLook
{
    /**
     * Every setting and the value it has until a level sets it: a standard card with
     * its image on top in its own shape and the name at full length. Null and '' leave
     * a value to the stylesheet (and through it to the site). A frame, when one is
     * asked for, is square.
     *
     * @var array<string, string|int|null>
     * @since  1.0.0
     */
    public const DEFAULTS = [
        'display' => 'grid',
        'columns' => 4,
        'gap' => 16,
        'perView' => 3,
        'spacing' => 16,
        'autoplay' => 0,
        'loop' => false,
        'arrows' => true,
        'dots' => false,
        'style' => 'standard',
        'textPosition' => 'bottom',
        'overlayBackground' => '',
        'imagePosition' => 'top',
        'imageWidth' => null,
        'padding' => null,
        'imageHeight' => null,
        'fit' => 'natural',
        'ratio' => '1-1',
        'focus' => 'center',
        'imageRadius' => null,
        'titleLines' => 0,
        'titleWeight' => '',
        'titleSize' => '',
        'border' => null,
        'radius' => null,
        'shadow' => '',
        'shadowHover' => '',
    ];

    /**
     * @var array<string, string[]> The values each choice accepts.
     * @since  1.0.0
     */
    public const CHOICES = [
        'display' => ['grid', 'slider'],
        'style' => ['standard', 'overlay'],
        'textPosition' => ['bottom', 'middle', 'top'],
        'imagePosition' => ['top', 'left', 'right', 'bottom'],
        'fit' => ['cover', 'contain', 'natural'],
        'ratio' => ['16-9', '16-10', '4-3', '1-1', '3-4'],
        'focus' => ['center', 'top', 'bottom', 'left', 'right'],
        'titleWeight' => ['300', '400', '500', '600', '700', '800'],
    ];

    /**
     * @var string[] The settings that are on or off: a slider's loop, arrows and dots. (What a product card shows is the shop's.)
     * @since  1.0.0
     */
    public const SWITCHES = ['loop', 'arrows', 'dots'];

    /**
     * The number settings and their bounds: the grid's columns and its gap in px, a slider's cards per view, the space between
     * them in px and its autoplay in seconds (0 = off); then a side image's share of the card's width
     * in %, the image's greatest height in px (0 = no limit) and the name's lines
     * (0 = no limit).
     *
     * @var array<string, array{0: int, 1: int}>
     * @since  1.0.0
     */
    public const NUMBERS = [
        'columns' => [1, 8],
        'gap' => [0, 64],
        'perView' => [1, 8],
        'spacing' => [0, 64],
        'autoplay' => [0, 60],
        'imageWidth' => [10, 90],
        'imageHeight' => [0, 2000],
        'titleLines' => [0, 20],
    ];

    /**
     * The settings that hold four numbers, and each number's bounds in px: the card's
     * padding and its border's width on each side (top, right, bottom, left), the
     * radius of each corner of the card and of its image (top left, top right, bottom
     * right, bottom left). One number stands for all four.
     *
     * @var array<string, array{0: int, 1: int}>
     * @since  1.0.0
     */
    public const SIDES = [
        'padding' => [0, 200],
        'border' => [0, 20],
        'radius' => [0, 999],
        'imageRadius' => [0, 999],
    ];

    /**
     * @var string[] The settings that hold a font size, a number and its unit (UNITS): the product's name.
     * @since  1.0.0
     */
    public const SIZES = ['titleSize'];

    /**
     * @var string[] The units a size may be in.
     * @since  1.0.0
     */
    public const UNITS = ['px', 'em', 'rem', '%'];

    /**
     * The settings every screen has a default of its own for (deviceDefault()): how many
     * cards fit side by side. A value set for wider screens does not reach a narrower
     * one (a phone shows one card whatever the desktop was told) until it is set there.
     *
     * @var string[]
     * @since  1.0.0
     */
    public const PER_DEVICE = ['columns', 'perView'];

    /**
     * @var array<string, int> The settings that hold a CSS value, and their longest length: an overlay card's text ground and the card's shadows.
     * @since  1.0.0
     */
    public const TEXTS = ['overlayBackground' => 300, 'shadow' => 200, 'shadowHover' => 200];

    /**
     * @var string[] The settings the page is built with rather than styled by: a breakpoint cannot change them.
     * @since  1.0.0
     */
    public const BASE_ONLY = ['display'];

    /**
     * The settings by the group the forms show them in: the grid or slider the cards sit in, then the
     * card (its layout, image, text and outline).
     *
     * @var array<string, string[]>
     * @since  1.0.0
     */
    public const GROUPS = [
        'display' => ['display', 'columns', 'gap', 'perView', 'spacing', 'autoplay', 'loop', 'arrows', 'dots'],
        'layout' => ['style', 'textPosition', 'overlayBackground', 'imagePosition', 'imageWidth', 'padding'],
        'image' => ['imageHeight', 'fit', 'ratio', 'focus', 'imageRadius'],
        'text' => ['titleLines', 'titleWeight', 'titleSize'],
        'box' => ['border', 'radius', 'shadow', 'shadowHover'],
    ];

    /**
     * When a setting has an effect: any one of the listed conditions, each naming the
     * values another setting must have. The forms hide a setting that has none.
     *
     * @var array<string, array<int, array<string, string[]>>>
     * @since  1.0.0
     */
    public const WHEN = [
        'columns' => [['display' => ['grid']]],
        'gap' => [['display' => ['grid']]],
        'perView' => [['display' => ['slider']]],
        'spacing' => [['display' => ['slider']]],
        'autoplay' => [['display' => ['slider']]],
        'loop' => [['display' => ['slider']]],
        'arrows' => [['display' => ['slider']]],
        'dots' => [['display' => ['slider']]],
        'textPosition' => [['style' => ['overlay']]],
        'overlayBackground' => [['style' => ['overlay']]],
        'imagePosition' => [['style' => ['standard']]],
        'imageWidth' => [['style' => ['standard'], 'imagePosition' => ['left', 'right']]],
        'imageHeight' => [['style' => ['standard']]],
        'fit' => [['style' => ['standard']]],
        'ratio' => [['style' => ['overlay']], ['fit' => ['cover', 'contain']]],
        'focus' => [['style' => ['overlay']], ['fit' => ['cover']]],
        'imageRadius' => [['style' => ['standard']]],
    ];

    /**
     * @var array<string, string> The form field each setting was stored in before a level became one value.
     * @since  1.0.0
     */
    private const LEGACY = [
        'display' => 'display',
        'columns' => 'columns',
        'gap' => 'gap',
        'perView' => 'per_view',
        'spacing' => 'slide_spacing',
        'autoplay' => 'slider_autoplay',
        'loop' => 'slider_loop',
        'arrows' => 'show_arrows',
        'dots' => 'show_dots',
        'style' => 'card_style',
        'textPosition' => 'card_text_position',
        'overlayBackground' => 'card_overlay_background',
        'imagePosition' => 'card_image_position',
        'imageWidth' => 'card_image_width',
        'imageHeight' => 'card_image_height',
        'fit' => 'card_image_fit',
        'ratio' => 'card_image_ratio',
        'focus' => 'card_image_focus',
        'titleLines' => 'card_title_lines',
        'titleWeight' => 'card_title_weight',
        'border' => 'card_border',
        'radius' => 'card_radius',
        'imageRadius' => 'card_image_radius',
        'shadow' => 'card_shadow',
        'shadowHover' => 'card_shadow_hover',
    ];

    /**
     * @var array<string, array<string, string>> The form field a setting was stored in for one of the module's two widths, before a level became one value.
     * @since  1.0.0
     */
    private const LEGACY_BP = [
        'columns' => ['tablet' => 'columns_tablet', 'mobile' => 'columns_mobile'],
        'perView' => ['tablet' => 'per_view_tablet', 'mobile' => 'per_view_mobile'],
    ];

    /**
     * @var string A card inside the module (`%s` = its place, as attribute selectors).
     * @since  1.0.0
     */
    private const CARD = '.mod-alfa-items .item-card%s';

    /**
     * @var string A slider's track around the cards of a place (`%s` = the place): shadows need room inside it.
     * @since  1.0.0
     */
    private const TRACK = '.mod-alfa-items .slider:has(.item-card%s) .track';

    /**
     * What a card may declare that the stylesheet has no value for, so a narrower
     * screen cannot take it back by declaring another: written for exactly the screens
     * that want it. The name's link takes the card's colour on an overlay and the
     * site's own everywhere else.
     *
     * @var array<string, string[]> Selector => properties.
     * @since  1.0.0
     */
    private const RANGED = [' .item-card-title a' => ['color']];

    // Reading a level

    /**
     * A level from its stored value: the JSON a form writes, already decoded or not.
     * One flat set of settings is read as every-screen settings. Anything else is an
     * empty level.
     *
     * @param mixed $value The stored value.
     *
     * @return array{base: array<string, mixed>, bp: array<string, array<string, mixed>>}
     *
     * @since  1.0.0
     */
    public static function fromValue(mixed $value): array
    {
        if (\is_string($value)) {
            $value = json_decode($value, true);
        } elseif (\is_object($value)) {
            $value = json_decode((string) json_encode($value), true);
        }

        $level = ['base' => [], 'bp' => []];

        if (!\is_array($value)) {
            return $level;
        }

        if (!isset($value['base']) && !isset($value['bp'])) {
            $level['base'] = self::partial($value);

            return $level;
        }

        $level['base'] = self::partial(\is_array($value['base'] ?? null) ? $value['base'] : []);

        foreach (\is_array($value['bp'] ?? null) ? $value['bp'] : [] as $key => $settings) {
            if (!\is_array($settings) || !preg_match('/^[a-z0-9-]{1,80}$/', (string) $key)) {
                continue;
            }

            $partial = array_diff_key(self::partial($settings), array_flip(self::BASE_ONLY));

            if ($partial !== []) {
                $level['bp'][(string) $key] = $partial;
            }
        }

        return $level;
    }

    /**
     * A level from a form's values: its `card` value (with a `card_box` beside it, where
     * the module kept the card's outline for a while). What a level was stored in before
     * it became one value (a form field per setting) fills in whatever the value does
     * not hold: a form saved since has none of those fields left, so they only ever
     * speak for a module that was not saved yet.
     *
     * @param array $values The module's parameters, or a tab row.
     *
     * @return array{base: array<string, mixed>, bp: array<string, array<string, mixed>>}
     *
     * @since  1.0.0
     */
    public static function level(array $values): array
    {
        $level = ['base' => [], 'bp' => []];

        foreach ([$values['card'] ?? '', $values['card_box'] ?? ''] as $value) {
            $part = self::fromValue($value);
            $level['base'] += $part['base'];

            foreach ($part['bp'] as $key => $settings) {
                $level['bp'][$key] = ($level['bp'][$key] ?? []) + $settings;
            }
        }

        $before = [];

        foreach (self::LEGACY as $key => $field) {
            if (isset($values[$field]) && !\array_key_exists($key, $level['base'])) {
                $before[$key] = $values[$field];
            }
        }

        $level['base'] += self::partial($before);

        foreach (self::LEGACY_BP as $key => $fields) {
            foreach ($fields as $breakpoint => $field) {
                if (isset($values[$field]) && !isset($level['bp'][$breakpoint][$key])) {
                    $level['bp'][$breakpoint] = ($level['bp'][$breakpoint] ?? []) + self::partial([$key => $values[$field]]);
                }
            }
        }

        $level['bp'] = array_filter($level['bp']);

        return $level;
    }

    /**
     * The settings among raw values keyed like DEFAULTS, each checked against what it
     * accepts: a choice among its values, a switch as on or off, a number within its
     * bounds, four numbers within theirs, a size with its unit, a CSS value cleaned. A
     * missing, empty or unknown value is left out, so it follows whatever is above it.
     *
     * @param array $values Raw values.
     *
     * @return array<string, string|int|bool|int[]>
     *
     * @since  1.0.0
     */
    public static function partial(array $values): array
    {
        $partial = [];

        foreach (array_keys(self::DEFAULTS) as $key) {
            $value = $values[$key] ?? '';

            if (isset(self::SIDES[$key])) {
                $sides = self::sides(value: $value, min: self::SIDES[$key][0], max: self::SIDES[$key][1]);

                if ($sides !== null) {
                    $partial[$key] = $sides;
                }
            } elseif (isset(self::NUMBERS[$key])) {
                if (is_numeric($value)) {
                    $partial[$key] = max(self::NUMBERS[$key][0], min(self::NUMBERS[$key][1], (int) $value));
                }
            } elseif (\in_array($key, self::SIZES, true)) {
                $size = self::size($value);

                if ($size !== '') {
                    $partial[$key] = $size;
                }
            } elseif (isset(self::TEXTS[$key])) {
                $css = self::cssValue($value, self::TEXTS[$key]);

                if ($css !== '') {
                    $partial[$key] = $css;
                }
            } elseif (\in_array($key, self::SWITCHES, true)) {
                if (\in_array($value, [true, 1, '1'], true)) {
                    $partial[$key] = true;
                } elseif (\in_array($value, [false, 0, '0'], true)) {
                    $partial[$key] = false;
                }
            } elseif (\is_string($value) && \in_array($value, self::CHOICES[$key], true)) {
                $partial[$key] = $value;
            }
        }

        return $partial;
    }

    // Laying levels and breakpoints over each other

    /**
     * The card on every device: '' is every screen, then one entry per breakpoint in
     * the order given (widest first). On a device each level counts with what it sets
     * for every screen and for the breakpoints down to that one, the narrower over the
     * wider; the levels are then laid over the defaults, the later over the earlier.
     * A setting with a default of its own on every screen (PER_DEVICE) is not carried
     * down: a device has what a level sets for that very device, else its own default.
     *
     * @param array $levels Levels, the module's first and the most specific last.
     * @param array $breakpoints The module's breakpoints (key, max), widest first.
     *
     * @return array<string, array<string, mixed>> Device ('' or a breakpoint key) => the whole card there.
     *
     * @since  1.0.0
     */
    public static function resolve(array $levels, array $breakpoints): array
    {
        $resolved = [];
        $carried = array_fill(0, \count($levels), []);
        $perDevice = array_flip(self::PER_DEVICE);

        foreach (array_merge([['key' => '', 'max' => 0]], array_values($breakpoints)) as $breakpoint) {
            $device = (string) $breakpoint['key'];
            $card = self::DEFAULTS;

            foreach (self::PER_DEVICE as $key) {
                $card[$key] = self::deviceDefault(key: $key, max: (int) $breakpoint['max']);
            }

            foreach (array_values($levels) as $index => $level) {
                $own = $device === '' ? ($level['base'] ?? []) : ($level['bp'][$device] ?? []);
                $carried[$index] = array_replace(array_diff_key($carried[$index], $perDevice), $own);
                $card = array_replace($card, $carried[$index]);
            }

            $resolved[$device] = $card;
        }

        return $resolved;
    }

    /**
     * What a screen shows of a PER_DEVICE setting until a level says otherwise: the
     * setting's default on screens wider than every breakpoint, two side by side up to
     * a tablet's width, one on anything narrower than 768px.
     *
     * @param string $key A PER_DEVICE setting.
     * @param int $max The width the device applies up to; 0 = wider than every breakpoint.
     *
     *
     * @since  1.0.0
     */
    public static function deviceDefault(string $key, int $max): int
    {
        return match (true) {
            $max <= 0 => (int) self::DEFAULTS[$key],
            $max >= 768 => min(2, (int) self::DEFAULTS[$key]),
            default => 1,
        };
    }

    /**
     * A slider's settings on every device, widest first, as the storefront script and
     * the slider's layout read them: `max` is the width a device applies up to (0 =
     * wider than every breakpoint) and `autoplay` is in milliseconds.
     *
     * @param array<string, array<string, mixed>> $resolved The place on every device (resolve()).
     * @param array $breakpoints The module's breakpoints (key, max), widest first.
     *
     * @return array<int, array{key: string, max: int, perView: int, spacing: int, autoplay: int, loop: bool, arrows: bool, dots: bool}>
     *
     * @since  1.0.0
     */
    public static function bands(array $resolved, array $breakpoints): array
    {
        $bands = [];

        foreach (array_merge([['key' => '', 'max' => 0]], array_values($breakpoints)) as $breakpoint) {
            $there = $resolved[$breakpoint['key']] ?? null;

            if ($there === null) {
                continue;
            }

            $bands[] = [
                'key' => (string) $breakpoint['key'],
                'max' => (int) $breakpoint['max'],
                'perView' => (int) $there['perView'],
                'spacing' => (int) $there['spacing'],
                'autoplay' => (int) $there['autoplay'] * 1000,
                'loop' => (bool) $there['loop'],
                'arrows' => (bool) $there['arrows'],
                'dots' => (bool) $there['dots'],
            ];
        }

        return $bands;
    }

    // Rules

    /**
     * Everything a whole card declares, by selector: '' is the card, ' ...' a part of
     * it, '@track' the slider track around it. An overlay card's image fills the card
     * and covers it, so it has no position, size, fit or corners of its own, and the
     * padding is the text's; an image in its own shape has no frame; only a covering
     * image has a focus. Values left to the stylesheet (null, '') declare nothing.
     *
     * @param array $card A whole card.
     *
     * @return array<string, array<string, string>> Selector => property => value.
     *
     * @since  1.0.0
     */
    public static function declarations(array $card): array
    {
        $card += self::DEFAULTS;
        $overlay = $card['style'] === 'overlay';
        $fit = $overlay ? 'cover' : $card['fit'];
        $framed = $fit !== 'natural';
        $beside = !$overlay && \in_array($card['imagePosition'], ['left', 'right'], true);
        $height = $overlay ? 0 : (int) $card['imageHeight'];
        $shrunk = !$framed && $height > 0;
        $px = static fn (array $sides): string => implode(' ', array_map(static fn (int $side): string => $side . 'px', $sides));
        $padding = $card['padding'] !== null ? $px($card['padding']) : null;

        $rules = [
            '' => [
                'display' => $overlay ? 'grid' : 'flex',
                'flex-direction' => $overlay ? 'column' : ['top' => 'column', 'left' => 'row', 'right' => 'row-reverse', 'bottom' => 'column-reverse'][$card['imagePosition']],
                'align-items' => $beside ? 'flex-start' : 'center',
                'gap' => $beside ? '10px' : '0',
                'text-align' => $beside ? 'start' : 'center',
                'padding' => $overlay ? '0' : ($padding ?? 'var(--alfa-items-card-padding, 10px)'),
                'overflow' => $overlay ? 'hidden' : 'visible',
                'color' => $overlay ? 'var(--alfa-items-overlay-color)' : 'inherit',
                'background' => $overlay ? 'var(--alfa-items-overlay)' : 'none',
            ],
            ' > *' => ['grid-area' => $overlay ? '1 / 1' : 'auto'],
            ' .item-card-image' => [
                'width' => $framed ? '100%' : 'auto',
                'aspect-ratio' => $framed ? str_replace('-', ' / ', $card['ratio']) : 'auto',
                'overflow' => $framed ? 'hidden' : 'visible',
                'align-self' => $overlay ? 'stretch' : 'auto',
                'flex' => $beside ? '0 0 ' . (int) ($card['imageWidth'] ?? 40) . '%' : '0 1 auto',
                'max-height' => $framed && $height > 0 ? $height . 'px' : 'none',
            ],
            ' .item-card-image > a' => [
                'display' => $framed ? 'block' : 'inline',
                'height' => $framed ? '100%' : 'auto',
            ],
            ' .item-card-image img' => [
                'width' => $shrunk ? 'auto' : '100%',
                'height' => $framed ? '100%' : 'auto',
                'max-height' => $shrunk ? $height . 'px' : 'none',
                'object-fit' => $fit === 'cover' ? 'cover' : 'contain',
                'object-position' => $fit === 'cover' ? ['center' => 'center', 'top' => 'center top', 'bottom' => 'center bottom', 'left' => 'left center', 'right' => 'right center'][$card['focus']] : 'center',
            ],
            ' .item-card-body' => [
                'flex' => $beside ? '1 1 0' : '1 1 auto',
                'align-items' => $beside ? 'stretch' : 'center',
                'z-index' => $overlay ? '1' : 'auto',
                'align-self' => $overlay ? ['bottom' => 'end', 'middle' => 'center', 'top' => 'start'][$card['textPosition']] : 'auto',
                'padding' => $overlay ? ($padding ?? ['bottom' => '3rem 10px 10px', 'middle' => '10px', 'top' => '10px 10px 3rem'][$card['textPosition']]) : '0',
                'background' => $overlay ? ($card['overlayBackground'] !== '' ? $card['overlayBackground'] : 'var(--alfa-items-overlay-' . $card['textPosition'] . ')') : 'none',
            ],
            // A template that balances its headings spreads the text over ALL its lines,
            // the hidden ones too (Firefox): whoever clamps wraps greedily.
            ' .item-card-title' => (int) $card['titleLines'] > 0
                ? ['display' => '-webkit-box', '-webkit-box-orient' => 'vertical', 'overflow' => 'hidden', '-webkit-line-clamp' => (string) (int) $card['titleLines'], 'text-wrap' => 'wrap']
                : ['display' => 'block', 'overflow' => 'visible', '-webkit-line-clamp' => 'none'],
            '@track' => ['padding-block' => $card['shadow'] !== '' || $card['shadowHover'] !== '' ? '12px' : '0'],
        ];

        // What the stylesheet reads as a custom property, with the site's value behind it.
        foreach (['border' => '--alfa-items-card-border', 'radius' => '--alfa-items-card-radius'] as $key => $property) {
            if ($card[$key] !== null) {
                $rules[''][$property] = $px($card[$key]);
            }
        }

        foreach (['shadow' => '--alfa-items-card-shadow', 'shadowHover' => '--alfa-items-card-shadow-hover'] as $key => $property) {
            if ($card[$key] !== '') {
                $rules[''][$property] = $card[$key];
            }
        }

        if (!$overlay && $card['imageRadius'] !== null) {
            $rules['']['--alfa-items-card-image-radius'] = $px($card['imageRadius']);
        }

        if ($card['titleWeight'] !== '') {
            $rules[' .item-card-title']['font-weight'] = $card['titleWeight'];
        }

        if ($card['titleSize'] !== '') {
            $rules[' .item-card-title']['font-size'] = $card['titleSize'];
        }

        if ($overlay) {
            $rules[' .item-card-title a']['color'] = 'inherit';
        }

        return $rules;
    }

    /**
     * What the stylesheet itself declares: the default card.
     *
     * @return array<string, array<string, string>>
     *
     * @since  1.0.0
     */
    public static function baseline(): array
    {
        return self::declarations(self::DEFAULTS);
    }

    /**
     * The rules of a module instance: for every place, what its cards declare that the
     * stylesheet does not, then inside each breakpoint's media query what changes from
     * the wider screens. Places that declare the same share their rules. A declaration
     * no narrower screen could take back (RANGED) is written for exactly the screens
     * that want it, or once, when they all do.
     *
     * @param array<string, array<string, array<string, mixed>>> $places Place name => its card on every device (resolve()).
     * @param array<int, array{key: string, label?: string, max: int}> $breakpoints The module's breakpoints, widest first.
     *
     * @return string CSS.
     *
     * @since  1.0.0
     */
    public static function css(array $places, array $breakpoints): string
    {
        $bodies = [];
        $devices = array_merge([['key' => '', 'max' => 0]], array_values($breakpoints));

        foreach ($places as $place => $resolved) {
            $before = self::baseline();
            $blocks = [];
            $ranged = [];

            foreach ($devices as $index => $breakpoint) {
                if (!isset($resolved[$breakpoint['key']])) {
                    continue;
                }

                $now = self::declarations($resolved[$breakpoint['key']]);

                foreach (self::RANGED as $selector => $properties) {
                    foreach ($properties as $property) {
                        if (isset($now[$selector][$property])) {
                            $ranged[$index][$selector][$property] = $now[$selector][$property];
                            unset($now[$selector][$property]);
                        }
                    }

                    if (($now[$selector] ?? null) === []) {
                        unset($now[$selector]);
                    }
                }

                $blocks[$index] = self::changes($before, $now);
                $before = $now;
            }

            $body = ['blocks' => $blocks, 'ranged' => $ranged];
            $bodies[json_encode($body)]['places'][] = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $place);
            $bodies[json_encode($body)] += $body;
        }

        $css = [];
        $exact = '';
        $rule = static function (string $selector, array $declarations): string {
            $out = $selector . '{';

            foreach ($declarations as $property => $value) {
                $out .= $property . ':' . $value . ';';
            }

            return rtrim($out, ';') . '}';
        };

        foreach ($bodies as $body) {
            $names = array_map(static fn (string $place): string => '[data-look="' . $place . '"]', $body['places']);
            $place = \count($names) === 1 ? $names[0] : ':is(' . implode(',', $names) . ')';

            foreach ($body['blocks'] as $index => $changes) {
                foreach ($changes as $selector => $declarations) {
                    $css[$index] = ($css[$index] ?? '') . $rule($selector === '@track' ? sprintf(self::TRACK, $place) : sprintf(self::CARD, $place) . $selector, $declarations);
                }
            }

            // Every screen wants the same: once, outside any query. Else each screen's own width range.
            $everywhere = \count($body['ranged']) === \count($devices) && \count(array_unique(array_map('json_encode', $body['ranged']))) === 1;

            foreach ($body['ranged'] as $index => $selectors) {
                $query = [];

                if ($index > 0) {
                    $query[] = '(max-width:' . (int) $devices[$index]['max'] . 'px)';
                }

                if (isset($devices[$index + 1])) {
                    $query[] = '(min-width:' . ((int) $devices[$index + 1]['max'] + 1) . 'px)';
                }

                $rules = '';

                foreach ($selectors as $selector => $declarations) {
                    $rules .= $rule(sprintf(self::CARD, $place) . $selector, $declarations);
                }

                if ($everywhere || $query === []) {
                    $exact .= $index === 0 || !$everywhere ? $rules : '';
                } else {
                    $exact .= '@media ' . implode(' and ', $query) . '{' . $rules . '}';
                }
            }
        }

        $out = $css[0] ?? '';

        // Per device, not per width: two breakpoints of one width are still two devices.
        foreach (array_values($breakpoints) as $index => $breakpoint) {
            if (($css[$index + 1] ?? '') !== '') {
                $out .= '@media (max-width:' . (int) $breakpoint['max'] . 'px){' . $css[$index + 1] . '}';
            }
        }

        return $out . $exact;
    }

    /**
     * What one set of declarations changes in another: every value that differs, and a
     * custom property the card no longer sets handed back to the stylesheet. (A plain
     * property only ever goes missing beside companions that switch it off.)
     *
     * @param array<string, array<string, string>> $before The declarations in force.
     * @param array<string, array<string, string>> $now The declarations wanted.
     *
     * @return array<string, array<string, string>>
     *
     * @since  1.0.0
     */
    private static function changes(array $before, array $now): array
    {
        $changes = [];

        foreach ($now as $selector => $declarations) {
            foreach ($declarations as $property => $value) {
                if (($before[$selector][$property] ?? null) !== $value) {
                    $changes[$selector][$property] = $value;
                }
            }
        }

        foreach ($before as $selector => $declarations) {
            foreach (array_diff_key($declarations, $now[$selector] ?? []) as $property => $value) {
                if (str_starts_with($property, '--')) {
                    $changes[$selector][$property] = 'initial';
                }
            }
        }

        return $changes;
    }

    // Values

    /**
     * Four numbers from a stored value (one number standing for all four, four
     * numbers, or a list of either), each bounded; null when the value holds neither,
     * so the setting follows whatever is above it.
     *
     * @param mixed $value The stored value: 8, '8', '8 8 0 0' or [8, 8, 0, 0].
     * @param int $min The smallest number kept.
     * @param int $max The largest number kept.
     *
     * @return int[]|null Four numbers, in CSS order.
     *
     * @since  1.0.0
     */
    public static function sides(mixed $value, int $min, int $max): ?array
    {
        if (\is_array($value)) {
            $parts = array_values($value);
        } else {
            $parts = \is_scalar($value) ? preg_split('/[\s,]+/', trim((string) $value), -1, PREG_SPLIT_NO_EMPTY) : [];
        }

        $parts = array_values(array_filter($parts, 'is_numeric'));

        if (\count($parts) !== 1 && \count($parts) !== 4) {
            return null;
        }

        $numbers = array_map(static fn ($part): int => max($min, min($max, (int) $part)), $parts);

        return \count($numbers) === 1 ? array_fill(0, 4, $numbers[0]) : $numbers;
    }

    /**
     * A font size from a stored value: a number and its unit (UNITS), as `1.25rem`. A
     * bare number (a size stored in pixels before it had a unit) is pixels.
     *
     * @param mixed $value The stored value.
     *
     * @return string '' when it is not a size, or is zero.
     *
     * @since  1.0.0
     */
    public static function size(mixed $value): string
    {
        if (!\is_scalar($value) || !preg_match('/^(\d{1,3}(?:\.\d{1,3})?)\s*(' . implode('|', self::UNITS) . ')?$/', trim((string) $value), $match)) {
            return '';
        }

        $number = (float) $match[1];

        return $number > 0 ? rtrim(rtrim(number_format($number, 3, '.', ''), '0'), '.') . ($match[2] ?? 'px') : '';
    }

    /**
     * A CSS value a merchant typed, reduced to what a colour, a gradient or a shadow is
     * made of: letters, digits, spaces and # % ( ) , . / + -, so it can never end the
     * declaration it is written into or open another.
     *
     * @param mixed $value The stored value.
     * @param int $length The longest value kept.
     *
     * @return string '' when nothing usable is left, or its brackets do not pair up.
     *
     * @since  1.0.0
     */
    public static function cssValue(mixed $value, int $length): string
    {
        if (!\is_string($value)) {
            return '';
        }

        $css = trim(substr(preg_replace(['/[^a-z0-9#%(),.\s\/+-]/i', '/\s+/'], ['', ' '], $value) ?? '', 0, $length));

        // A function left open (a typo, or a value cut short at $length) would take the
        // rule's closing brace, and the rules after it, into itself.
        $depth = 0;

        foreach (str_split($css) as $char) {
            $depth += $char === '(' ? 1 : ($char === ')' ? -1 : 0);

            if ($depth < 0) {
                return '';
            }
        }

        return $depth === 0 ? $css : '';
    }
}
