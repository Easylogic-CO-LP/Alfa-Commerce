<?php

declare(strict_types=1);

namespace libphonenumber\Leniency;

use libphonenumber\PhoneNumber;
use libphonenumber\PhoneNumberUtil;
use RuntimeException;

/**
 * @no-named-arguments
 * @since  1.0.0
 */
abstract class AbstractLeniency
{
    /**
     * Integer level to compare 'ENUMs'
     */
    protected static int $level;

    /**
     * Returns true if $number is a verified number according to this leniency
     *
     * @codeCoverageIgnore
     * @since  1.0.0
     */
    abstract public static function verify(PhoneNumber $number, string $candidate, PhoneNumberUtil $util): bool;

    /**
     * Compare against another Leniency
     * @since  1.0.0
     */
    public static function compareTo(AbstractLeniency $leniency): int
    {
        return static::getLevel() - $leniency::getLevel();
    }

    protected static function getLevel(): int
    {
        if (!isset(static::$level)) {
            throw new RuntimeException('$level should be defined');
        }

        return static::$level;
    }

    public function __toString()
    {
        return str_replace('libphonenumber\\Leniency\\', '', get_class($this));
    }
}
