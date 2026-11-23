<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Search;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\General\LayoutEvent;

/**
 * Lets the CONFIGURED search engine take over rendering of the storefront search input.
 *
 * `onAlfaSearchInputLayout`, fired by the `list.filter_form` layout where the input
 * renders, broadcast to the `alfa-search` group. A listener claims the input by setting
 * its layout on this event (setLayout / setLayoutData / setLayoutPluginType /
 * setLayoutPluginName): the input is one whole layout that replaces the stock one, not a
 * slot on a page's surface map, so this event carries the layout itself. When nothing
 * claims it, the layout renders Joomla's stock searchtools input exactly as before, so a
 * site with no search plugins, the overwhelmingly common case, pays nothing and changes
 * nothing.
 *
 * WHY THIS EXISTS: so an engine's own input rendering ships WITH the engine: a richer
 * input lands here, and because the plugin layout resolves through
 * {@see \Alfa\Component\Alfa\Site\Helper\Layout::pluginLayout()}, a template may override
 * it at `html/plg_alfa-search_<element>/<layout>.php` like any other plugin layout. It
 * renders the input only: an engine's consumer of the result set's attribution loads when
 * the items view announces the result set ({@see ResultSetRenderEvent}).
 *
 * Arguments: `subject` = the list view (exposes `filterForm` and the document); `method` =
 * the CONFIGURED engine key, read straight from the component's global configuration
 * through SearchEngineRegistry::configuredKey() (never discovered; '' for the database
 * search), playing the role a payment method plays in the payments group:
 * "which one of you is being asked". A listener claims the input ONLY when the key is its
 * own, so exactly one engine ever owns the input and an enabled-but-not-configured engine
 * stays silent. `context` = which surface is rendering the input (`com_alfa.items`,
 * `com_alfa.manufacturers`, ...), because this layout serves MORE THAN ONE list view and a
 * listener may legitimately dress the product search differently from the brand search,
 * or claim only one of them.
 *
 * Redirects ({@see LayoutEvent} inherits them) are NOT honoured at this call site; a
 * filter form being decorated is no place to navigate a shopper away from.
 *
 * @since  2.0.0
 */
class SearchInputLayoutEvent extends LayoutEvent
{
    /**
     * @var string The layout that renders the input ('' = the stock input).
     * @since  2.3.0
     */
    private string $layout = '';

    /**
     * @var array The layout's display data, merged over the view and the context.
     * @since  2.3.0
     */
    private array $layoutData = [];

    /**
     * @var string The plugin group owning the layout ('' = a layout of the component).
     * @since  2.3.0
     */
    private string $layoutPluginType = '';

    /**
     * @var string The plugin element owning the layout ('' = a layout of the component).
     * @since  2.3.0
     */
    private string $layoutPluginName = '';

    /**
     * Set the layout that renders the input in place of the stock one.
     *
     * @param string $layout The layout name (without .php).
     *
     * @since  2.3.0
     */
    public function setLayout(string $layout): void
    {
        $this->layout = $layout;
    }

    /**
     * The layout that renders the input ('' when no engine claimed it).
     *
     * @since  2.3.0
     */
    public function getLayout(): string
    {
        return $this->layout;
    }

    /**
     * Set the display data of the input's layout.
     *
     * @param array $data The display data.
     *
     * @since  2.3.0
     */
    public function setLayoutData(array $data): void
    {
        $this->layoutData = $data;
    }

    /**
     * The display data of the input's layout.
     *
     * @since  2.3.0
     */
    public function getLayoutData(): array
    {
        return $this->layoutData;
    }

    /**
     * Set the plugin group that owns the input's layout.
     *
     * @param string $type The plugin group.
     *
     * @since  2.3.0
     */
    public function setLayoutPluginType(string $type): void
    {
        $this->layoutPluginType = $type;
    }

    /**
     * The plugin group that owns the input's layout.
     *
     * @since  2.3.0
     */
    public function getLayoutPluginType(): string
    {
        return $this->layoutPluginType;
    }

    /**
     * Set the plugin element that owns the input's layout.
     *
     * @param string $name The plugin element.
     *
     * @since  2.3.0
     */
    public function setLayoutPluginName(string $name): void
    {
        $this->layoutPluginName = $name;
    }

    /**
     * The plugin element that owns the input's layout.
     *
     * @since  2.3.0
     */
    public function getLayoutPluginName(): string
    {
        return $this->layoutPluginName;
    }

    /**
     * Get the list view subject carried by the event.
     *
     * @return mixed The list view object (exposes filterForm and the document)
     *
     * @since  2.0.0
     */
    public function getView()
    {
        return $this->getSubject();
    }

    /**
     * The configured engine key this input belongs to.
     *
     * A readable alias for the inherited `method` argument, so listeners read
     * `$event->getEngineKey()` instead of wondering what a "method" is here.
     *
     *
     * @since  2.0.0
     */
    public function getEngineKey(): string
    {
        return (string) $this->getMethod();
    }

    /**
     * Which surface is rendering the input.
     *
     * Dotted `com_alfa.<view>` (`com_alfa.items`, `com_alfa.manufacturers`, ...). Carried
     * explicitly rather than duck-typed off the subject, because the same layout serves
     * several list views and "which page am I decorating" is part of the question being
     * asked, not something a listener should reverse-engineer.
     *
     * @return string The context; '' when the dispatching layout did not say.
     *
     * @since  2.0.0
     */
    public function getContext(): string
    {
        return (string) ($this->arguments['context'] ?? '');
    }
}
