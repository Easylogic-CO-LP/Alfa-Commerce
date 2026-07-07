<?php
    namespace Joomla\Plugin\System\GeoLoader\Extension;

    defined('_JEXEC') or die;

    use Joomla\CMS\Plugin\CMSPlugin;
    use Joomla\Event\SubscriberInterface;
    use Joomla\Utilities\IpHelper;
    use Joomla\CMS\Http\HttpFactory;

    class GeoLoader extends CMSPlugin implements SubscriberInterface
    {

        public static function getSubscribedEvents(): array
        {
            return [
                'onAfterInitialise' => 'onAfterInitialise',
            ];
        }

        public function onAfterInitialise()
        {
            $app = $this->getApplication();


            if ($app->isClient('administrator')) {
                return;
            }

            $session = $app->getSession();


            if ($session->has('ecommerce_user_country')) {
                return;
            }


            $apiToken       = $this->params->get('api_token', '');
            $fallbackDefault = $this->params->get('default_country', 'US');


            $countryCode = $fallbackDefault;

            $ip = IpHelper::getIp();


            if ($ip === '::1' || $ip === '127.0.0.1') {
                $ip = '8.8.8.8';
            }



            try {
                $url = "https://ipinfo.io{$ip}/json";
                if (!empty($apiToken)) {
                    $url .= "?token=" . urlencode($apiToken);
                }



                $http = HttpFactory::getHttp();

                $http->setOption('timeout', 2);

                $response = $http->get($url);


                if ($response && $response->code === 200) {
                    $data = json_decode($response->body);
                    if (!empty($data->country)) {
                        $countryCode = strtoupper($data->country);
                    }
                } else {


                    throw new \RuntimeException('API returned non-200 status code: ' . ($response->code ?? 'unknown'));
                }
            } catch (\Exception $e) {

            }


            $session->set('ecommerce_user_country', $countryCode);
        }
    }