<?php

namespace Alfa\PhpKlarna\Exceptions;

defined('_JEXEC') or die;
use Exception;
use Throwable;

/** Thrown on HTTP 404 — order / token / session not found in Klarna. */
class NotFoundException extends Exception
{
    public function __construct(string $message = 'Klarna resource not found.', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
