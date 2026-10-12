<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Plugin;

use Alfa\Component\Alfa\Administrator\Event\Search\CollectSearchEnginesEvent;
use Alfa\Component\Alfa\Administrator\Service\Search\RebuildRunner;
use Alfa\Component\Alfa\Administrator\Service\Search\RebuildStore;
use Alfa\Component\Alfa\Administrator\Service\Search\SearchEngineInterface;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;
use Throwable;

defined('_JEXEC') or die;

/**
 * Abstract base for `alfa-search` plugins: one per search engine.
 *
 *  Writing an engine plugin
 *
 * Everything below is supplied for you: registration with the engine registry, the
 * Tools -> Rebuild card, the chunked walk across the catalogue, the durable
 * checkpoint, reconciliation against products saved mid-rebuild, the refusal to
 * activate an incomplete generation, the progress bar and every language string. A
 * complete engine plugin is one method:
 *
 * <code>
 * final class Example extends SearchEnginePlugin
 * {
 *     protected function engine(): SearchEngineInterface
 *     {
 *         return new ExampleEngine($this->params);
 *     }
 * }
 * </code>
 *
 * That is the whole contract. You never name an event class, never subscribe to
 * anything, never touch {@see RebuildRunner} or {@see RebuildStore}, and never write
 * a line of JavaScript to draw the progress bar. What you DO write is the engine
 * itself: {@see SearchEngineInterface}, which is where all the interesting decisions
 * live: how documents are stored, how a query is matched, and when the engine is
 * ready to answer.
 *
 *  What you may override
 *
 *   engine()            REQUIRED. Your {@see SearchEngineInterface} instance.
 *   isRebuildOwed()     Return true when your stored index no longer matches the
 *                       merchant's settings. Turns the card amber and shows the
 *                       "rebuild owed" notice. Default: false (never nags).
 *   rebuildTimeout()    Seconds to wait for an asynchronous engine to drain before
 *                       the new generation is activated. Default: 30.
 *   documentSource()    Where documents come from. Default is the whole catalogue,
 *                       and there is almost never a reason to change it: a second
 *                       source would be a second definition of "the catalogue".
 *
 * Adding your own Tools actions is ordinary: override onGetToolActions(), call
 * `parent::onGetToolActions($event)` first, then add yours.
 *
 *  The division of labour this class enforces
 *
 * THE ACTION IS THE PLUGIN'S; THE ALGORITHM IS NOT. Core ships no rebuild button:
 * an installation with no engine has no index to rebuild, and a core button naming
 * one would put core back in the business of knowing that stored search documents
 * exist. The button appears because a plugin extended this class. What core owns is
 * the WALK: enumeration, chunking, the durable checkpoint, reconciliation against
 * live saves, and the refusal to activate an incomplete generation. Those are
 * correctness rules, and correctness must not fork per engine.
 *
 * The action id is `search.rebuild` for every engine, not `example.rebuild`, because
 * EXECUTION routes by recorded ownership rather than by parsing names: the endpoint
 * is asked for `search.rebuild` owned by `alfa-search/example`, and it reaches
 * that plugin and no other.
 *
 * COLLECTION keys by owner + id as well: {@see \Alfa\Component\Alfa\Administrator\Helper\ToolRegistry::actions()}
 * indexes on `owner_group/owner_name:action_id`, so two installed engines declaring
 * `search.rebuild` are two distinct actions end to end; found as a real defect by
 * installing a second engine, fixed in the registry, not worked around here.
 *
 * @since  1.5.0
 */
abstract class SearchEnginePlugin extends CMSPlugin implements SubscriberInterface
{
    /**
     * The rebuild card is rendered from string keys; without this the merchant reads
     * raw constants.
     *
     * @var bool
     *
     * @since  1.5.0
     */
    protected $autoloadLanguage = true;

    /**
     * The engine this plugin contributes.
     *
     * Called once per collection and once per rebuild request, so it may construct
     * freely, but an engine that opens a network connection in its constructor
     * should defer that to first use, since discovery runs on every storefront search.
     *
     *
     * @since  1.5.0
     */
    abstract protected function engine(): SearchEngineInterface;

    /**
     * Subscribed events.
     *
     * Subclasses that need more should merge rather than replace:
     * `array_merge(parent::getSubscribedEvents(), [...])`.
     *
     * @return array<string, string>
     *
     * @since  1.5.0
     */
    public static function getSubscribedEvents(): array
    {
        // Engine discovery only. Rebuilding the product index is the COMPONENT's operation over
        // the component's own data, offered by CoreToolProvider; this class carried it once and
        // that made an engine look like the owner of something it never owned. An engine now says
        // only what it can about rebuilding, through Service\Search\RebuildableEngine.
        return [
            'onCollectSearchEngines' => 'onCollectSearchEngines',
        ];
    }

    /**
     * Contribute this engine to the registry.
     *
     * @param CollectSearchEnginesEvent $event The collection event.
     *
     *
     * @since  1.5.0
     */
    public function onCollectSearchEngines(CollectSearchEnginesEvent $event): void
    {
        $event->addEngine($this->engine(), $this->extensionElement(), $this->extensionVersion());
    }

    /**
     * This plugin's extension element, for the registry's ownership record.
     *
     *
     * @since  1.5.0
     */
    private function extensionElement(): string
    {
        return 'plg_' . $this->_type . '_' . $this->_name;
    }

    /**
     * This plugin's version, read from its own manifest.
     *
     * @return string Empty when the manifest cannot be read; a diagnostic detail
     *                must never be the reason an engine fails to register.
     *
     * @since  1.5.0
     */
    private function extensionVersion(): string
    {
        try {
            $manifest = JPATH_PLUGINS . '/' . $this->_type . '/' . $this->_name . '/' . $this->_name . '.xml';

            if (!is_file($manifest)) {
                return '';
            }

            $xml = simplexml_load_file($manifest);

            return $xml === false ? '' : (string) $xml->version;
        } catch (Throwable $e) {
            return '';
        }
    }
}
