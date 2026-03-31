<?php
namespace Alfa\PhpKlarna\Exceptions;
defined('_JEXEC') or die;
use Exception;

/** Thrown on HTTP 400 (bad request), 401 (unauthorized), 403 (forbidden). */
class FailedActionException extends Exception
{
    public function __construct(string $message, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
