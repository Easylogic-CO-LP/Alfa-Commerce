<?php

/**
 * @package     Alfa\PhpKlarna
 * @copyright   Copyright (C) Alfa. All rights reserved.
 * @license     MIT
 */

namespace Alfa\PhpKlarna\Resources;

defined('_JEXEC') or die;

use Alfa\PhpKlarna\PhpKlarna;
use AllowDynamicProperties;
use ReflectionObject;
use ReflectionProperty;

/**
 * ApiResource — base class for all Klarna response objects.
 *
 * Hydrates public properties from the raw API response array,
 * converting snake_case keys to camelCase automatically.
 *
 * Example: 'order_id' → $this->orderId, 'fraud_status' → $this->fraudStatus
 *
 * #[AllowDynamicProperties] handles PHP 8.2+ strict mode when Klarna returns
 * fields not declared as typed properties on the subclass.
 */
#[AllowDynamicProperties]
class ApiResource
{
    /** Raw response array exactly as returned by the Klarna API. */
    public array $attributes = [];

    /** Back-reference to the client. Protected so credentials are never exposed. */
    protected ?PhpKlarna $klarna;

    public function __construct(array $attributes, ?PhpKlarna $klarna = null)
    {
        $this->attributes = $attributes;
        $this->klarna = $klarna;
        $this->fill();
    }

    /** Hydrate all attributes onto camelCase properties. */
    protected function fill(): void
    {
        foreach ($this->attributes as $key => $value) {
            $this->{$this->camelCase($key)} = $value;
        }
    }

    /**
     * Convert snake_case to camelCase.
     *   order_id            → orderId
     *   fraud_status        → fraudStatus
     *   payment_method_type → paymentMethodType
     */
    protected function camelCase(string $key): string
    {
        return lcfirst(str_replace('_', '', ucwords($key, '_')));
    }

    /**
     * Exclude $klarna and $attributes from serialization so API credentials
     * are never accidentally written to disk or logs.
     */
    public function __sleep(): array
    {
        $names = array_map(
            static fn (ReflectionProperty $p) => $p->getName(),
            (new ReflectionObject($this))->getProperties(ReflectionProperty::IS_PUBLIC),
        );

        return array_values(array_diff($names, ['klarna', 'attributes']));
    }
}
