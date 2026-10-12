<?php

/**
 * Alfa Commerce
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\General;

use BadMethodCallException;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * View tier: redirect + layout. Hooks rendered inside an HtmlView (cart, item,
 * product, order process/complete, admin order views) extend this: they either
 * render the plugin's layout fragment inline (setLayout) or send the buyer away
 * (setRedirectUrl, inherited from RedirectEvent).
 *
 * @since  1.0.0
 */
abstract class LayoutEvent extends RedirectEvent
{
    // Output travels as named arguments (setLayout/setLayoutData/setLayoutPluginType/
    // setLayoutPluginName, plus setRedirectUrl from RedirectEvent), so this event does
    // NOT use Joomla's Result* traits/interface (multi-listener result collection) nor
    // the ReshapeArgumentsAware legacy positional-argument b/c layer; listeners
    // receive named arguments only.

    /**
     * Constructor.
     *
     * @param string $name The event name.
     * @param array $arguments The event arguments.
     *
     * @throws BadMethodCallException
     *
     * @since  1.0.0
     */
    public function __construct($name, array $arguments = [])
    {
        parent::__construct($name, $arguments);

        if (!\array_key_exists('method', $this->arguments)) {
            throw new BadMethodCallException("Argument 'method' of event {$name} is required but has not been provided");
        }
    }

    /**
     * Setter for the fieldset argument.
     *
     *
     *
     * @since  1.0.0
     */
    protected function onSetMethod($value)
    {
        return $value;
    }

    /**
     * Getter for the fieldset.
     *
     *
     * @since  1.0.0
     */
    public function getMethod()
    {
        return $this->arguments['method'];
    }

    /**
     * Plugin TYPE that owns the layout fragment (used by the renderer to resolve
     * the override path). Empty string when unset.
     *
     *
     * @since  1.0.0
     */
    public function getLayoutPluginType(): string
    {
        return $this->arguments['layoutPluginType'] ?? '';
    }

    /**
     * Value filter for the 'layoutPluginType' con
     * struction/setArgument path
     * (Joomla magic): setArgument stores what this RETURNS, so it must return the
     * value. Plugins use the explicit setLayoutPluginType() below for convenience.
     *
     * @param string $value The plugin type.
     *
     * @return string
     *
     * @since  1.0.0
     */
    protected function onSetLayoutPluginType($value)
    {
        return $value;
    }

    /**
     * Store the layout plugin type (plugin convenience setter).
     *
     * @param string $value The plugin type to set.
     *
     *
     * @since  1.0.0
     */
    public function setLayoutPluginType($value): void
    {
        $this->arguments['layoutPluginType'] = $value;
    }

    /**
     * Plugin NAME that owns the layout fragment. Empty string when unset.
     *
     *
     * @since  1.0.0
     */
    public function getLayoutPluginName(): string
    {
        return $this->arguments['layoutPluginName'] ?? '';
    }

    /**
     * Value filter for the 'layoutPluginName' construction/setArgument path
     * (Joomla magic): must return the value. Plugins use setLayoutPluginName().
     *
     * @param string $value The plugin name.
     *
     * @return string
     *
     * @since  1.0.0
     */
    protected function onSetLayoutPluginName($value)
    {
        return $value;
    }

    /**
     * Store the layout plugin name (plugin convenience setter).
     *
     * @param string $value The plugin name to set.
     *
     *
     * @since  1.0.0
     */
    public function setLayoutPluginName($value): void
    {
        $this->arguments['layoutPluginName'] = $value;
    }

    /**
     * The layout fragment this plugin renders. Empty string when the plugin set
     * none; the orchestrator then renders no surface for this field.
     *
     *
     * @since  1.0.0
     */
    public function getLayout(): string
    {
        return $this->arguments['layout'] ?? '';
    }

    /**
     * Value filter for the 'layout' construction/setArgument path (Joomla magic):
     * must return the value. Plugins use the explicit setLayout() for convenience.
     *
     * @param string $value The layout name.
     *
     * @return string
     *
     * @since  1.0.0
     */
    protected function onSetLayout($value)
    {
        return $value;
    }

    /**
     * Store the layout fragment to render (plugin convenience setter).
     *
     * @param string $value The layout name (without extension).
     *
     *
     * @since  1.0.0
     */
    public function setLayout($value): void
    {
        $this->arguments['layout'] = $value;
    }

    /**
     * The data passed to the resolved layout's $displayData. Empty array when unset.
     *
     *
     * @since  1.0.0
     */
    public function getLayoutData(): array
    {
        return $this->arguments['layoutData'] ?? [];
    }

    /**
     * Value filter for the 'layoutData' construction/setArgument path (Joomla
     * magic): must return the value. Plugins use the explicit setLayoutData().
     *
     * @param array $value The layout data.
     *
     * @return array
     *
     * @since  1.0.0
     */
    protected function onSetLayoutData($value)
    {
        return $value;
    }

    /**
     * Store the layout data passed to the resolved layout (plugin convenience).
     *
     * @param array $value The layout data to set.
     *
     *
     * @since  1.0.0
     */
    public function setLayoutData($value): void
    {
        $this->arguments['layoutData'] = $value;
    }
}
