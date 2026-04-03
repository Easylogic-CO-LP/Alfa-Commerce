<?php

namespace Alfa\PhpViva;

defined('_JEXEC') or die;

abstract class Url
{
    public const LIVE_URL = '';
    public const TEST_URL = '';

    public static function getUrl(bool $testMode = false): string
    {
        return $testMode ? static::TEST_URL : static::LIVE_URL;
    }
}
