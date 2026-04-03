<?php

namespace Alfa\PhpViva\Account;

defined('_JEXEC') or die;
use Alfa\PhpViva\Url as BaseUrl;

class Url extends BaseUrl
{
    public const LIVE_URL = 'https://accounts.vivapayments.com';
    public const TEST_URL = 'https://demo-accounts.vivapayments.com';
}
