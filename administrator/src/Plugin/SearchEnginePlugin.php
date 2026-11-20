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
 * Registration with the engine registry (onCollectSearchEngines) is supplied for you. A complete engine plugin
 * is one method:
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
 * That is the whole contract of this class. You never name an event class and never subscribe to anything.
 * What you DO write is the engine itself: {@see SearchEngineInterface}, which is where all the interesting
 * decisions live: how documents are stored, how a query is matched, and when the engine is ready to answer.
 *
 *  What you write
 *
 *   engine()            REQUIRED, the one hook of this class: your {@see SearchEngineInterface} instance.
 *
 *  Rebuilding the index
 *
 * Rebuilding the product index is the COMPONENT's operation over the component's own data: its Tools screen
 * offers the rebuild ({@see \Alfa\Component\Alfa\Administrator\Service\Tools\CoreToolProvider}, action
 * `search.rebuild`), and core owns the walk: enumeration, chunking, the durable checkpoint, reconciliation
 * against products saved mid-rebuild and the refusal to activate an incomplete generation ({@see RebuildRunner},
 * {@see RebuildStore}). An engine that keeps an index says only what core cannot know, by implementing
 * {@see \Alfa\Component\Alfa\Administrator\Service\Search\RebuildableEngine}: what to call the button
 * (rebuildLabel()) and whether a rebuild is owed (isRebuildOwed()). An engine that does not implement it gets
 * no rebuild button.
 *
 * @since  2.0.0
 */
abstract class SearchEnginePlugin extends CMSPlugin implements SubscriberInterface
{
    /**
     * The rebuild card is rendered from string keys; without this the merchant reads
     * raw constants.
     *
     * @var bool
     *
     * @since  2.0.0
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
     * @since  2.0.0
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
     * @since  2.0.0
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
     * @since  2.0.0
     */
    public function onCollectSearchEngines(CollectSearchEnginesEvent $event): void
    {
        $event->addEngine($this->engine(), $this->_type . '.' . $this->_name, $this->extensionVersion());
    }

    /**
     * This plugin's version, read from its own manifest.
     *
     * @return string Empty when the manifest cannot be read; a diagnostic detail
     *                must never be the reason an engine fails to register.
     *
     * @since  2.0.0
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
