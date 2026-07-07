<?php


    namespace Alfa\Component\Alfa\Site\Helper;

    use Joomla\CMS\Http\HttpFactory;

    defined('_JEXEC') or die;
class GeoIpHelper
{
    public static function getCountryCode(?string $ip = null): ?string
    {
        $ip ??= \Joomla\Utilities\IpHelper::getIp();

        if (!$ip)
            {
                return null;
            }


        if ($country = self::getCountryFromDatabase($ip))
            {
                return $country;
            }

        if ($country = self::getCountryFromWebService($ip))
            {
                return $country;
            }

        return null;
    }


    protected static function getCountryFromDatabase(string $ip): ?string
    {
        return null;
    }

    protected static function getCountryFromWebService(string $ip): ?string
    {
        try
        {
            $http = HttpFactory::getHttp();

            $response = $http->get(
                'https://ip-api.com/json/' . urlencode($ip)
            );

            if ($response->code !== 200)
            {
                return null;
            }

            $data = json_decode($response->body);

            if (!empty($data->countryCode))
            {
                return strtoupper($data->countryCode);
            }
        }
        catch (\Throwable $e)
        {

        }

        return null;
    }
}