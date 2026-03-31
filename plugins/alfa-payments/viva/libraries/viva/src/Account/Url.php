<?php
namespace Alfa\PhpViva\Account;
defined('_JEXEC') or die;
use Alfa\PhpViva\Url as BaseUrl;

class Url extends BaseUrl
{
    const LIVE_URL = 'https://accounts.vivapayments.com';
    const TEST_URL = 'https://demo-accounts.vivapayments.com';
}
