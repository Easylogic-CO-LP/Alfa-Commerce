<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

/**
 * The "not set here" state for ANY field on a com_alfa inheritance chain
 * (entity -> menu item -> global config), labelled with the value that will
 * actually apply.
 *
 * Not tied to one setting: a select gets an empty first OPTION
 * ({@see self::inheritOption()}), a free-value input gets a placeholder HINT
 * ({@see self::inheritLabel()}); both name the chain's floor, the com_alfa
 * config param. The concrete field classes stay domain-named because each
 * carries domain behaviour (page-size labels, storefront-form scanning); this
 * trait is the shared chain plumbing. A future chained setting reuses it by
 * adding two XML attributes to its field.
 *
 * Joomla's own `useglobal` does the same job but binds the tier to the config
 * param by FIELD NAME: `ListField::getOptions()` looks up
 * `ComponentHelper::getParams($option)->get($this->fieldname)`. That silently
 * couples storage shape to naming: com_alfa keeps entity tiers inside JSON
 * columns while the menu and config tiers are flat params, so the link is
 * declared here instead, explicitly, via `globalparam`.
 *
 * The label is tier-aware because the chain is: a MENU item has nothing but the
 * config above it ("Use Global"), an ENTITY may still be intercepted by the
 * menu item that displays it ("Inherit"). Which menu that is cannot be known
 * while editing the entity, so the value shown is always the config's: the
 * chain's floor, and the value that applies whenever no menu overrides it.
 *
 * Attributes read from the field element:
 *   - globalparam   com_alfa config param naming the inherited value. Presence
 *                   is what switches the inherit state on: the config tier
 *                   itself is the root of the chain and simply omits it.
 *   - inheritlabel  language key for the wording (default JGLOBAL_USE_GLOBAL).
 *
 * @since  1.9.0
 */
trait InheritsGlobalParam
{
    /**
     * Whether this tier inherits from another (i.e. has an inherit state).
     *
     * @return bool True when `globalparam` names a config param
     * @since  1.9.0
     */
    protected function inherits(): bool
    {
        return (string) $this->element['globalparam'] !== '';
    }

    /**
     * The value this tier falls back to: the com_alfa config param named by
     * `globalparam`, which is the chain's floor.
     *
     * A shop that never SAVED its Options has no stored param, but the value
     * that actually applies is the config form's declared default (the same
     * one com_config bakes into the params on first save), so the hint shows
     * that rather than pretending nothing is inherited.
     *
     * @return string The effective inherited value, '' when the param has no
     *                stored value AND no declared default
     * @since  1.9.0
     */
    protected function inheritedValue(): string
    {
        $name = (string) $this->element['globalparam'];
        $value = ComponentHelper::getParams('com_alfa')->get($name);

        if ($value !== null && $value !== '') {
            return (string) $value;
        }

        return self::configDeclaredDefault($name);
    }

    /**
     * The `default` attribute config.xml declares for a param, '' when none.
     *
     * @param string $name The config param name
     *
     * @since  1.9.0
     */
    private static function configDeclaredDefault(string $name): string
    {
        static $defaults = null;

        if ($defaults === null) {
            $defaults = [];
            $xml = @simplexml_load_file(JPATH_ADMINISTRATOR . '/components/com_alfa/config.xml');

            foreach ($xml ? $xml->xpath('//field[@default]') : [] as $field) {
                $defaults[(string) $field['name']] = (string) $field['default'];
            }
        }

        return $defaults[$name] ?? '';
    }

    /**
     * The tier's wording for "not set here", naming the inherited value.
     *
     * @param string $shown How to render that value: its human label where one
     *                      exists ("Newest first" rather than "a.id DESC").
     *
     * @return string e.g. "Use Global (25)", "Inherit (Name (A-Z))", "Inherit"
     * @since  1.9.0
     */
    protected function inheritLabel(string $shown = ''): string
    {
        $label = Text::_((string) ($this->element['inheritlabel'] ?: 'JGLOBAL_USE_GLOBAL'));

        return $shown === '' ? $label : $label . ' (' . $shown . ')';
    }

    /**
     * Build the empty option, naming the inherited value when there is one.
     *
     * @param object[] $options The tier's real options, searched so the value
     *                          shows as its LABEL ("Newest first"), not its
     *                          stored form ("a.id DESC").
     *
     * @return object The select option for "not set here"
     * @since  1.9.0
     */
    protected function inheritOption(array $options): object
    {
        $value = $this->inheritedValue();

        foreach ($options as $option) {
            if ($value !== '' && (string) $option->value === $value) {
                $value = $option->text;

                break;
            }
        }

        return HTMLHelper::_('select.option', '', $this->inheritLabel($value));
    }
}
