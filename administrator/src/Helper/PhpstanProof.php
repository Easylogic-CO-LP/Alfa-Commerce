<?php

namespace Alfa\Component\Alfa\Administrator\Helper;

\defined('_JEXEC') or die;

/**
 * A throwaway class with a deliberate type error, pushed only to prove that the PHPStan gate blocks a pull request
 * into developer. Never merged.
 */
final class PhpstanProof
{
    public static function count(): int
    {
        return 'not an integer';
    }
}
