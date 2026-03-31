<?php
namespace Alfa\PhpKlarna\Resources;
defined('_JEXEC') or die;

/**
 * Generic success wrapper for action methods that return no specific resource
 * (acknowledge, cancel, capture, refund, extend-authorization, etc.).
 *
 * If the API returns a JSON body the attributes are hydrated as camelCase properties.
 * If the API returns an empty body (common on 201/204) the raw string is in $response.
 */
class RespondSuccess extends ApiResource
{
    public string $response = '';

    public function __construct(mixed $attributes, $klarna = null)
    {
        if (is_array($attributes)) {
            parent::__construct($attributes, $klarna);
        } else {
            $this->response = (string) $attributes;
        }
    }
}
