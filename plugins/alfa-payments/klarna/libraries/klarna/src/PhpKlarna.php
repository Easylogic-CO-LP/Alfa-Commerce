<?php
/**
 * @package     Alfa\PhpKlarna
 * @copyright   Copyright (C) Alfa. All rights reserved.
 * @license     MIT
 *
 * How to register this namespace in your Joomla plugin (no autoload.php needed):
 *
 *   \JLoader::registerNamespace('Alfa\\PhpKlarna', __DIR__ . '/klarna/src', false, false, 'psr4');
 *
 * Or if the files live in the plugin's own src/ folder, add to your plugin XML:
 *   <namespace path="src">Alfa\PhpKlarna</namespace>
 * and Joomla registers it automatically on install.
 */

namespace Alfa\PhpKlarna;

defined('_JEXEC') or die;

use Joomla\CMS\Date\Date;
use Joomla\CMS\Http\Http;
use Joomla\CMS\Http\HttpFactory;
use Alfa\PhpKlarna\Actions\ManageCheckouts;
use Alfa\PhpKlarna\Actions\ManageCustomerTokens;
use Alfa\PhpKlarna\Actions\ManageHostedPaymentPage;
use Alfa\PhpKlarna\Actions\ManageOrders;
use Alfa\PhpKlarna\Actions\ManagePayments;
use Alfa\PhpKlarna\Exceptions\ValidationException;

class PhpKlarna
{
    use MakesHttpRequests;
    use ManagePayments;
    use ManageHostedPaymentPage;
    use ManageCustomerTokens;
    use ManageOrders;
    use ManageCheckouts;

    /** Joomla HTTP client — replaceable for testing via setClient(). */
    public Http $client;

    /** Full base URL resolved from region + mode, e.g. https://api.klarna.com/ */
    protected string $baseUri = '';

    /** Headers sent with every request (Authorization, Accept, Content-Type). */
    protected array $defaultHeaders = [];

    /**
     * @param string $username  Klarna merchant API username (e.g. PK12345_abc...).
     * @param string $password  Klarna shared secret / API password.
     * @param string $region    EU | NA | OC  (default: EU)
     * @param string $mode      live | test    (default: live)
     *
     * @throws ValidationException on invalid region or mode.
     */
    public function __construct(
        string $username,
        string $password,
        string $region = 'EU',
        string $mode   = 'live'
    ) {
        $this->baseUri        = $this->getBaseUri($region, $mode);
        $this->defaultHeaders = [
            'Authorization' => 'Basic ' . base64_encode($username . ':' . $password),
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
        ];

        // (new HttpFactory())->getHttp() picks cURL or stream — ships with every Joomla install.
        $this->client = (new HttpFactory())->getHttp();
    }

    /** Replace the HTTP client (e.g. a mock for unit tests). */
    public function setClient(Http $client): static
    {
        $this->client = $client;

        return $this;
    }

    /** Merge extra headers into every subsequent request (User-Agent, Idempotency-Key, etc.). */
    public function setHeaders(array $headers): static
    {
        $this->defaultHeaders = array_merge($this->defaultHeaders, $headers);

        return $this;
    }

    /**
     * Convert any date string to the given PHP format.
     * Uses Joomla\CMS\Date\Date which extends \DateTime — zero extra dependencies.
     *
     * Example:
     *   convertDateFormat('2025-04-15T10:00:00Z')          → '20250415100000'
     *   convertDateFormat('2025-04-15T10:00:00Z', 'd/m/Y') → '15/04/2025'
     */
    public function convertDateFormat(string $date, string $format = 'YmdHis'): string
    {
        return (new Date($date))->format($format);
    }

    /**
     * Resolve the Klarna API base URI for the given region and mode.
     *
     * @throws ValidationException on unrecognised values.
     */
    public function getBaseUri(string $region, string $mode): string
    {
        $test = [
            'eu' => 'https://api.playground.klarna.com/',
            'na' => 'https://api-na.playground.klarna.com/',
            'oc' => 'https://api-oc.playground.klarna.com/',
        ];
        $live = [
            'eu' => 'https://api.klarna.com/',
            'na' => 'https://api-na.klarna.com/',
            'oc' => 'https://api-oc.klarna.com/',
        ];

        $r = strtolower($region);
        $m = strtolower($mode);

        if (!isset($live[$r])) {
            throw new ValidationException(['region' => 'Invalid region "' . $region . '". Must be EU, NA or OC.']);
        }

        if (!in_array($m, ['live', 'test'], true)) {
            throw new ValidationException(['mode' => 'Invalid mode "' . $mode . '". Must be live or test.']);
        }

        return $m === 'test' ? $test[$r] : $live[$r];
    }

    /** @internal Used by Action traits to transform raw arrays into typed Resource collections. */
    protected function transformCollection(array $collection, string $class): array
    {
        return array_map(fn(array $attrs) => new $class($attrs, $this), $collection);
    }
}
