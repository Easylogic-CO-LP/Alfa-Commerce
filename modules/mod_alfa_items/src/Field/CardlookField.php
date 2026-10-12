<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Module\AlfaItems\Site\Field;

use Alfa\Module\AlfaItems\Site\Helper\AlfaItemsHelper;
use Alfa\Module\AlfaItems\Site\Helper\CardLook;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\FileLayout;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;
use Throwable;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The card settings of one level (the module, or a tab) as one editor: the
 * settings in groups, and a device switch that sets any of them again for one
 * breakpoint. The form submits a single value, the level as CardLook reads it
 * (`{"base": {...}, "bp": {"<breakpoint>": {...}}}`), holding only what is set.
 *
 *     <field name="card" type="cardlook" level="module"/>
 *     <field name="card" type="cardlook" level="tab"/>
 *
 * The markup is the layout's (`layouts/field/cardlook.php`); look-editor.js keeps
 * the controls and the stored value in step.
 *
 * @since  1.0.0
 */
class CardlookField extends FormField
{
    /**
     * @var string
     * @since  1.0.0
     */
    protected $type = 'Cardlook';

    /**
     * @var array<string, string> Each setting's name in the language keys, after MOD_ALFA_ITEMS_CARD_.
     * @since  1.0.0
     */
    private const NAMES = [
        'display' => 'DISPLAY',
        'columns' => 'COLUMNS',
        'gap' => 'GAP',
        'perView' => 'PER_VIEW',
        'spacing' => 'SPACING',
        'autoplay' => 'AUTOPLAY',
        'loop' => 'LOOP',
        'arrows' => 'ARROWS',
        'dots' => 'DOTS',
        'style' => 'STYLE',
        'textPosition' => 'TEXT_POSITION',
        'overlayBackground' => 'OVERLAY_BACKGROUND',
        'imagePosition' => 'IMAGE_POSITION',
        'imageWidth' => 'IMAGE_WIDTH',
        'padding' => 'PADDING',
        'imageHeight' => 'IMAGE_HEIGHT',
        'fit' => 'IMAGE_FIT',
        'ratio' => 'IMAGE_RATIO',
        'focus' => 'IMAGE_FOCUS',
        'imageRadius' => 'IMAGE_RADIUS',
        'titleLines' => 'TITLE_LINES',
        'titleWeight' => 'TITLE_WEIGHT',
        'titleSize' => 'TITLE_SIZE',
        'border' => 'BORDER',
        'radius' => 'RADIUS',
        'shadow' => 'SHADOW',
        'shadowHover' => 'SHADOW_HOVER',
    ];

    /**
     * What an empty setting falls back to, shown in its box on the module's own
     * level: the stylesheet's value, or the language key of a word for it.
     *
     * @var array<string, string>
     * @since  1.0.0
     */
    private const FALLBACKS = [
        'columns' => '4',
        'gap' => '16',
        'perView' => '3',
        'spacing' => '16',
        'autoplay' => 'MOD_ALFA_ITEMS_CARD_HINT_OFF',
        'titleSize' => 'MOD_ALFA_ITEMS_CARD_HINT_INHERITED',
        'overlayBackground' => 'linear-gradient(to top, rgb(0 0 0 / 0.85), rgb(0 0 0 / 0.55) 60%, transparent)',
        'imageWidth' => '40',
        'padding' => '10',
        'imageHeight' => 'MOD_ALFA_ITEMS_CARD_HINT_NO_LIMIT',
        'imageRadius' => '0',
        'titleLines' => 'MOD_ALFA_ITEMS_CARD_HINT_NO_LIMIT',
        'border' => '1',
        'radius' => '0',
        'shadow' => 'none',
        'shadowHover' => 'none',
    ];

    /**
     * @var array<string, string[]> The custom properties behind a setting.
     * @since  1.0.0
     */
    private const VARIABLES = [
        'overlayBackground' => ['--alfa-items-overlay-bottom', '--alfa-items-overlay-middle', '--alfa-items-overlay-top'],
        'padding' => ['--alfa-items-card-padding'],
        'imageRadius' => ['--alfa-items-card-image-radius'],
        'border' => ['--alfa-items-card-border'],
        'radius' => ['--alfa-items-card-radius'],
        'shadow' => ['--alfa-items-card-shadow'],
        'shadowHover' => ['--alfa-items-card-shadow-hover'],
    ];

    /**
     * @var Registry|null The stored parameters of the module being edited, read once per request.
     * @since  1.0.0
     */
    private static ?Registry $stored = null;

    /**
     * Render the editor of this level.
     *
     * @return string
     *
     * @since  1.0.0
     */
    protected function getInput()
    {
        $level = (string) ($this->element['level'] ?? '') === 'tab' ? 'tab' : 'module';
        $asked = array_filter(array_map('trim', explode(',', (string) ($this->element['groups'] ?? ''))));
        $groups = $asked === [] ? array_keys(CardLook::GROUPS) : array_values(array_intersect(array_keys(CardLook::GROUPS), $asked));

        return self::editor(
            id:     (string) $this->id,
            name:   (string) $this->name,
            value:  self::withBefore(value: \is_string($this->value) ? trim($this->value) : '', level: $level, name: (string) $this->name),
            level:  $level,
            groups: $groups,
        );
    }

    /**
     * The editor's markup for one level, with its assets.
     *
     * @param string $id The id of the input holding the value.
     * @param string $name The name it is submitted under.
     * @param mixed $value The level as stored.
     * @param string $level module | tab: what an empty setting follows, and how much the editor explains.
     * @param string[] $groups The groups of settings to show (CardLook::GROUPS).
     *
     *
     * @since  1.0.0
     */
    public static function editor(string $id, string $name, mixed $value, string $level, array $groups): string
    {
        $wa = Factory::getApplication()->getDocument()->getWebAssetManager();

        // The module manager does not load this module's registry.
        $wa->getRegistry()->addExtensionRegistryFile('mod_alfa_items');
        $wa->useStyle('mod_alfa_items.look-editor')
            ->useScript('mod_alfa_items.look-editor');

        $stored = CardLook::fromValue($value);
        $keys = array_merge(...array_values(array_intersect_key(CardLook::GROUPS, array_flip($groups))));
        // A switch is a choice between '1' and '0' in the editor.
        $forEditor = static fn (array $settings): array => array_map(static fn ($setting) => \is_bool($setting) ? ($setting ? '1' : '0') : $setting, array_intersect_key($settings, array_flip($keys)));
        $breakpoints = self::breakpoints();
        $inherit = Text::_('MOD_ALFA_ITEMS_LOOK_INHERIT_' . strtoupper($level));

        return (new FileLayout('field.cardlook', JPATH_ROOT . '/modules/mod_alfa_items/layouts'))->render([
            'id' => $id,
            'name' => $name,
            'level' => $level,
            'groups' => $groups,
            'controls' => self::controls($level),
            'breakpoints' => $breakpoints,
            'inherit' => $inherit,
            'value' => json_encode(['base' => (object) $forEditor($stored['base']), 'bp' => (object) array_filter(array_map($forEditor, $stored['bp']))]),
            'config' => [
                'level' => $level,
                'defaults' => array_map(static fn ($default): string => \is_bool($default) ? ($default ? '1' : '0') : ($default === null ? '' : (string) $default), CardLook::DEFAULTS),
                'numbers' => CardLook::NUMBERS,
                'sides' => CardLook::SIDES,
                'texts' => CardLook::TEXTS,
                'baseOnly' => CardLook::BASE_ONLY,
                'perDevice' => CardLook::PER_DEVICE,
                'groups' => CardLook::GROUPS,
                'when' => CardLook::WHEN,
                'breakpoints' => $breakpoints,
                'live' => false,
                'labels' => [
                    'inherit' => $inherit,
                    'wider' => Text::_('MOD_ALFA_ITEMS_LOOK_INHERIT_WIDER'),
                    'everyScreen' => Text::_('MOD_ALFA_ITEMS_LOOK_NOTE_EVERY_SCREEN'),
                    'upTo' => Text::_('MOD_ALFA_ITEMS_LOOK_NOTE_UP_TO'),
                    'fixed' => Text::_('MOD_ALFA_ITEMS_LOOK_FIXED'),
                ],
            ],
        ]);
    }

    /**
     * Every setting as the layout draws it: its label and description, its kind of
     * control with what that needs, what an empty box falls back to and the custom
     * properties behind it.
     *
     * @param string $level module | tab.
     *
     * @return array<string, array{label: string, description: string, type: string, options?: array<string, string>, min?: int, max?: int, edges?: string, units?: string[], fallback: string, variables: string[]}> type: choice | switch | number | sides | size | text
     *
     * @since  1.0.0
     */
    private static function controls(string $level): array
    {
        $controls = [];

        foreach (self::NAMES as $key => $name) {
            $prefix = 'MOD_ALFA_ITEMS_CARD_' . $name;
            $fallback = self::FALLBACKS[$key] ?? '';
            $control = [
                'label' => Text::_($prefix . '_LBL'),
                'description' => Text::_($prefix . '_DESC'),
                'type' => 'choice',
                // Only the module's own level falls back to the stylesheet; the others follow the level above.
                'fallback' => $level !== 'module' ? '' : (str_starts_with($fallback, 'MOD_') ? Text::_($fallback) : $fallback),
                'variables' => $level === 'module' ? (self::VARIABLES[$key] ?? []) : [],
            ];

            if (\in_array($key, CardLook::SIZES, true)) {
                $control['type'] = 'size';
                $control['units'] = CardLook::UNITS;
            } elseif (isset(CardLook::TEXTS[$key])) {
                $control['type'] = 'text';
                $control['max'] = CardLook::TEXTS[$key];
            } elseif (isset(CardLook::SIDES[$key])) {
                $control['type'] = 'sides';
                [$control['min'], $control['max']] = CardLook::SIDES[$key];
                $control['edges'] = \in_array($key, ['radius', 'imageRadius'], true) ? 'corners' : 'sides';
            } elseif (isset(CardLook::NUMBERS[$key])) {
                $control['type'] = 'number';
                [$control['min'], $control['max']] = CardLook::NUMBERS[$key];
            } elseif (\in_array($key, CardLook::SWITCHES, true)) {
                // The arrows and the dots are shown or hidden; a loop is on or off.
                $control['type'] = 'switch';
                $control['options'] = $key === 'loop' ? ['1' => Text::_('JYES'), '0' => Text::_('JNO')] : ['1' => Text::_('JSHOW'), '0' => Text::_('JHIDE')];
            } else {
                $control['options'] = [];

                foreach (CardLook::CHOICES[$key] as $value) {
                    $control['options'][$value] = Text::_($prefix . '_' . strtoupper(str_replace('-', '_', $value)));
                }
            }

            $controls[$key] = $control;
        }

        return $controls;
    }

    /**
     * The field's value with what the level was stored in before it became one value,
     * a form field per setting, read from the stored module (CardLook::level), so
     * opening the form and saving it keeps them. On the module's own level, where those
     * fields stored every setting, a value that is the default anyway is left out.
     *
     * @param string $value The field's value.
     * @param string $level module | tab.
     * @param string $name The field's name: a tab's carries its row's position (`...[tabs][tabs0][card]`).
     *
     * @return string JSON, or '' while the level sets nothing.
     *
     * @since  1.0.0
     */
    private static function withBefore(string $value, string $level, string $name): string
    {
        $stored = self::stored()->toArray();

        if ($level === 'tab') {
            // The form renders the stored rows in their order under fresh names (tabs0, tabs1...):
            // the row's number is its position, not a key of the stored list.
            $rows = array_values((array) ($stored['tabs'] ?? []));
            $stored = preg_match('/\[tabs(\d+)\]\[[a-z_]+\]$/', $name, $match) ? (array) ($rows[(int) $match[1]] ?? []) : [];
        }

        $held = CardLook::fromValue($value);
        $all = CardLook::level(['card' => $value] + $stored);

        if ($level === 'module') {
            foreach ($all['base'] as $key => $setting) {
                if (!\array_key_exists($key, $held['base']) && $setting === CardLook::DEFAULTS[$key]) {
                    unset($all['base'][$key]);
                }
            }

            foreach (array_column(self::breakpoints(), 'max', 'key') as $key => $max) {
                foreach (CardLook::PER_DEVICE as $setting) {
                    if (!isset($held['bp'][$key][$setting]) && ($all['bp'][$key][$setting] ?? null) === CardLook::deviceDefault(key: $setting, max: (int) $max)) {
                        unset($all['bp'][$key][$setting]);
                    }
                }
            }

            $all['bp'] = array_filter($all['bp']);
        }

        return $all['base'] === [] && $all['bp'] === [] ? '' : (string) json_encode($all);
    }

    /**
     * The module's breakpoints, as the editor lists them: its two fixed widths.
     *
     * @return array<int, array{key: string, label: string, max: int}>
     *
     * @since  1.0.0
     */
    private static function breakpoints(): array
    {
        return array_map(
            static fn (array $breakpoint): array => $breakpoint + ['label' => Text::_('MOD_ALFA_ITEMS_LOOK_DEVICE_' . strtoupper($breakpoint['key']))],
            AlfaItemsHelper::BREAKPOINTS,
        );
    }

    /**
     * The stored parameters of the module being edited (none for a new one).
     *
     *
     * @since  1.0.0
     */
    private static function stored(): Registry
    {
        if (self::$stored !== null) {
            return self::$stored;
        }

        self::$stored = new Registry();

        try {
            $id = Factory::getApplication()->getInput()->getInt('id', 0);

            if ($id > 0) {
                $db = Factory::getContainer()->get(DatabaseInterface::class);
                $query = $db->getQuery(true)
                    ->select($db->quoteName('params'))
                    ->from($db->quoteName('#__modules'))
                    ->where($db->quoteName('id') . ' = :id')
                    ->where($db->quoteName('module') . ' = ' . $db->quote('mod_alfa_items'))
                    ->bind(':id', $id, ParameterType::INTEGER);
                self::$stored = new Registry((string) $db->setQuery($query)->loadResult());
            }
        } catch (Throwable $e) {
            // A new module: nothing stored.
        }

        return self::$stored;
    }
}
