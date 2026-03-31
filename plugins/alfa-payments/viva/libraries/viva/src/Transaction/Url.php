<?php
namespace Alfa\PhpViva\Transaction;
defined('_JEXEC') or die;
use Alfa\PhpViva\Url as BaseUrl;

class Url extends BaseUrl
{
    const LIVE_URL = 'https://api.vivapayments.com';
    const TEST_URL = 'https://demo-api.vivapayments.com';
}
