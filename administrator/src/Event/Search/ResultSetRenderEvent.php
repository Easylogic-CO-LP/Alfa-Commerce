<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Search;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Search\ResultAttribution;
use Joomla\CMS\Event\AbstractEvent;

/**
 * A result set is being rendered (2.2.0): `onAlfaResultSetRender`, dispatched by the items view on every rendered
 * result set (the full page, the chrome-less fragment a filter swap inserts, a single card's reload), whatever the
 * handle, the filters option or the plugins present, with the `alfa-search` group imported first.
 *
 * Through it an installed engine attaches its own consumer: a listener may add its own assets and script options
 * through the view's document; it renders no markup. A listener's failure is caught and logged by the view, and the
 * listing renders.
 *
 * Arguments, read through the getters (each reads its argument itself: Joomla's getArgument() calls a get<Name>()
 * getter of the same name): `subject` the view; `context` `com_alfa.items`; `kind` `page`, `fragment` or
 * `card`; `engine` the configured engine's key (the component option search_engine); `attribution` the result set's
 * ResultAttribution (its handle may be null; for a card, only the position its reload carried).
 *
 * @since  2.2.0
 */
class ResultSetRenderEvent extends AbstractEvent
{
    /**
     * A full page.
     *
     * @since  2.2.0
     */
    public const KIND_PAGE = 'page';

    /**
     * A chrome-less fragment (tmpl other than '', index or offline), which a filter swap inserts.
     *
     * @since  2.2.0
     */
    public const KIND_FRAGMENT = 'fragment';

    /**
     * A single card's reload.
     *
     * @since  2.2.0
     */
    public const KIND_CARD = 'card';

    /**
     * The view rendering the result set; its document gives the Web Asset Manager and the script options.
     *
     * @since  2.2.0
     */
    public function getView(): object
    {
        return $this->arguments['subject'];
    }

    /**
     * The surface: `com_alfa.items`.
     *
     * @since  2.2.0
     */
    public function getContext(): string
    {
        return (string) ($this->arguments['context'] ?? '');
    }

    /**
     * What is rendered: self::KIND_PAGE, self::KIND_FRAGMENT or self::KIND_CARD.
     *
     * @since  2.2.0
     */
    public function getKind(): string
    {
        return (string) ($this->arguments['kind'] ?? '');
    }

    /**
     * The configured engine's key: a listener acts only when it is its own.
     *
     * @since  2.2.0
     */
    public function getEngineKey(): string
    {
        return (string) ($this->arguments['engine'] ?? '');
    }

    /**
     * The result set's attribution (its handle may be null).
     *
     * @since  2.2.0
     */
    public function getAttribution(): ResultAttribution
    {
        $attribution = $this->arguments['attribution'] ?? null;

        return $attribution instanceof ResultAttribution ? $attribution : new ResultAttribution();
    }

    /**
     * The position a card's reload carried, or null (only for self::KIND_CARD).
     *
     * @since  2.2.0
     */
    public function getPosition(): ?int
    {
        $position = $this->arguments['position'] ?? null;

        return \is_int($position) ? $position : null;
    }
}
