<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Api;

\defined('_JEXEC') or die;

use Joomla\CMS\Event\AbstractEvent;
use Joomla\CMS\Router\ApiRouter;

/**
 * An alfa plugin declares the REST API routes it answers (onAlfaBeforeApiRoute): addressed to that plugin alone, never
 * raised on the shared dispatcher. Its handler is the plugin's public method of the event's name; a plugin without it
 * adds no routes, and its addresses answer 404.
 *
 * The plugin writes its routes on the router this event carries, with Joomla's own calls, as a webservices plugin does
 * on onBeforeApiRoute:
 *
 *   public function onAlfaBeforeApiRoute(ApiRoutesEvent $event): void
 *   {
 *       $event->getRouter()->addRoutes([
 *           new Route(['GET'], $event->getPrefix() . '/status', 'base.status', [], [
 *               'component' => 'com_alfa',
 *               'alfa_permission' => 'alfa.base.status',
 *           ]),
 *       ]);
 *   }
 *
 * The router is one of the plugin's own for this call, empty: core reads the routes back, checks each against the rules
 * of {@see \Alfa\Component\Alfa\Administrator\Service\Api\ApiRouteRegistrar::accept()} and puts the accepted ones on the
 * application's router after core's own, under the plugin's prefix, v1/alfa/ext/<element>. A route that breaks a rule is
 * not added, and the plugin's other routes are; nothing is logged while a request is routed (before its token is
 * checked): the API routes page lists the refused route with the rule it breaks, and the log com_alfa.api gets a line
 * for it when that screen reads the routes.
 *
 * @since  2.3.0
 */
final class ApiRoutesEvent extends AbstractEvent
{
    /**
     * @param ApiRouter $router The plugin's own router for this call, empty.
     * @param string $element The plugin's element, as it stands in its addresses.
     *
     * @since  2.3.0
     */
    public function __construct(private readonly ApiRouter $router, private readonly string $element)
    {
        parent::__construct('onAlfaBeforeApiRoute', ['router' => $router, 'element' => $element]);
    }

    /**
     * The router the plugin writes its routes on: createCRUDRoutes(), addRoutes(), addRoute(), get(), post(), patch(),
     * put() and delete(), as on Joomla's own.
     *
     * @since  2.3.0
     */
    public function getRouter(): ApiRouter
    {
        return $this->router;
    }

    /**
     * The plugin's element, as it stands in its addresses (base for plg_alfa_base).
     *
     * @since  2.3.0
     */
    public function getElement(): string
    {
        return $this->element;
    }

    /**
     * The start every route of the plugin's has: v1/alfa/ext/<element>, without a closing slash.
     *
     * @since  2.3.0
     */
    public function getPrefix(): string
    {
        return 'v1/alfa/ext/' . $this->element;
    }
}
