<?php
namespace Alfa\PhpKlarna\Exceptions;
defined('_JEXEC') or die;
use Exception;

class ValidationException extends Exception
{
    public array $errors = [];

    public function __construct(array $errors)
    {
        $this->errors = $errors;
        parent::__construct('Klarna validation failed: ' . print_r($errors, true));
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
