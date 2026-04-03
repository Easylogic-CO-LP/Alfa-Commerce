<?php

namespace Alfa\PhpViva\Transaction;

defined('_JEXEC') or die;
use Alfa\PhpViva\Url as BaseUrl;

class Url extends BaseUrl
{
    public const LIVE_URL = 'https://api.vivapayments.com';
    public const TEST_URL = 'https://demo-api.vivapayments.com';
}
