<?php

/**
 * Alfa Commerce
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Plugin\Traits;

use Alfa\Component\Alfa\Administrator\Service\Order\PaymentEnvironment;
use InvalidArgumentException;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The two component-level endpoints a third party can come back to.
 *
 * A Joomla plugin has no router and no controller of its own, so a gateway or
 * a carrier cannot be pointed at one. The component provides the entry points
 * and addresses the plugin by name (`&method=<element>`), which is why both
 * URLs are built here rather than hand-written in each plugin.
 *
 * Shared by payments and shipments: the shapes are identical and only the task
 * noun differs, so {@see self::transportTask()} is the single thing a family
 * has to answer.
 *
 * @since  2.0.0
 */
trait HasTransportUrlsTrait
{
    /**
     * The controller noun this family's endpoints live under: 'payment' or
     * 'shipment'. Implemented by the family base, never by a leaf plugin:
     * a plugin choosing its own transport would be a plugin able to receive
     * another family's callbacks. Static (2.2.1): it is the family's, not one
     * plugin's, so the address of any plugin of the family is built from it.
     *
     *
     * @since  2.0.0
     */
    abstract protected static function transportTask(): string;

    /**
     * Where a third party sends the BUYER back to, through their browser.
     *
     * Payments: the bank's redirect after the card form. Shipments: a parcel
     * locker or pickup-point picker the buyer was sent out to choose from.
     *
     * Carries only `&method=`, deliberately. EVERYTHING ELSE IS THE PLUGIN'S
     * CHOICE: one whose return message identifies the order by itself (a
     * signed reference, its own code) needs nothing more and should send
     * nothing more. One that does not builds what it needs and passes $extra.
     *
     * Usage:
     *   $this->returnUrl($order);
     *   $this->returnUrl($order, ['statusId' => 'X']);
     *
     * @param object $order Order being paid for or shipped.
     * @param array $extra Query params this plugin wants appended.
     *
     * @return string Absolute, TLS-forced.
     *
     * @since  2.0.0
     */
    protected function returnUrl(object $order, array $extra = []): string
    {
        $url = 'index.php?option=com_alfa&task=' . static::transportTask() . '.response&method=' . $this->_name;

        foreach ($extra as $key => $value) {
            if ((string) $value !== '') {
                $url .= '&' . rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
            }
        }

        return Route::_($url, false, Route::TLS_FORCE, true);
    }

    /**
     * The task a provider's server calls this family's plugins on:
     * payment.webhook or shipment.webhook. System - Alfa Commerce Sync reads
     * it to recognise such a request before Joomla routes it.
     *
     *
     * @since  2.2.1
     */
    public static function webhookTask(): string
    {
        return static::transportTask() . '.webhook';
    }

    /**
     * The static server-to-server address of the plugin $element of this
     * family, in its plain form: the site root, TLS forced, and
     * index.php?option=com_alfa&task=<family>.webhook&method=<element>, with
     * &environment=<set> for a payment gateway's credential set.
     *
     * Never routed (2.2.1): the address a provider holds names no language and
     * no SEF path, so it is the same whoever reads it and whatever languages
     * the site has. While System - Alfa Commerce Sync is enabled with Access
     * Public, a GET to it is answered by the controller, not redirected: the
     * plugin marks the request before Joomla routes it. A POST always reaches
     * the controller. An address registered in the routed form before 2.2.1
     * still reaches the same controller.
     *
     * Carries NO per-order parameters by design: a gateway or carrier is
     * configured with ONE URL in its portal and posts every notification to
     * it, so anything order-specific here would be a URL that only works once.
     *
     * A payment gateway names the credential set the notification belongs to
     * (PaymentEnvironment::ADDRESS_PARAM): one address per set, so the handler
     * checks it with that set's secret and no other.
     *
     * @param string $element The plugin element the address names: a plugin element, written as given.
     * @param string|null $environment PaymentEnvironment::SANDBOX or ::LIVE; null for an address without a set.
     *
     * @return string Absolute, TLS-forced.
     *
     * @throws InvalidArgumentException On an environment other than sandbox or live.
     *
     * @since  2.2.1
     */
    public static function webhookUrlFor(string $element, ?string $environment = null): string
    {
        $query = ['option' => 'com_alfa', 'task' => static::webhookTask(), 'method' => $element];

        if ($environment !== null) {
            $query[PaymentEnvironment::ADDRESS_PARAM] = PaymentEnvironment::assert(environment: $environment);
        }

        $url = new Uri(Uri::root() . 'index.php');
        $url->setScheme('https');
        $url->setQuery($query);

        return $url->toString();
    }

    /**
     * The static server-to-server endpoint for this plugin, in the plain form
     * of {@see self::webhookUrlFor()}.
     *
     * @param string|null $environment PaymentEnvironment::SANDBOX or ::LIVE; null for an address without a set.
     *
     * @return string Absolute, TLS-forced.
     *
     * @throws InvalidArgumentException On an environment other than sandbox or live.
     *
     * @since  2.0.0
     */
    protected function webhookUrl(?string $environment = null): string
    {
        return static::webhookUrlFor($this->_name, $environment);
    }
}
