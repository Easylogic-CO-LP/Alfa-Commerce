<?php

namespace Alfa\Component\Alfa\Administrator\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\Response\JsonResponse;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Input\Input;
use RuntimeException;
use stdClass;

/**
 * AJAX tile rendering for the MediaZone field of the item, category and manufacturer forms.
 *
 * A plain controller: there is no media record to add, edit or save, so none of
 * FormController's tasks belong here. getSource is the only task.
 *
 * @since  1.0.0
 */
class MediaController extends BaseController
{
    /**
     * Keep prepareExistingMedia() off the task map. It stays public only because
     * MediaZoneField calls it on an instance; it is not a request endpoint.
     *
     * @param array $config An optional associative array of configuration settings.
     * @param MVCFactoryInterface|null $factory The factory.
     * @param CMSApplicationInterface|null $app The Application for the dispatcher.
     * @param Input|null $input The Input object for the request.
     *
     * @since  1.10.0
     */
    public function __construct($config = [], ?MVCFactoryInterface $factory = null, ?CMSApplicationInterface $app = null, ?Input $input = null)
    {
        parent::__construct($config, $factory, $app, $input);

        $this->unregisterTask('prepareExistingMedia');
    }

    /**
     * Media Controller - AJAX getSource Method
     * Handles business logic and prepares data for template
     * @since  1.0.0
     */

    public function getSource()
    {
        $app = Factory::getApplication();

        // CSRF: this endpoint renders markup and performs an outbound URL check,
        // so it must not be reachable through a forged cross-site request.
        if (!Session::checkToken()) {
            echo new JsonResponse(null, Text::_('JINVALID_TOKEN_NOTICE'), true);
            $app->close();
        }

        if (!$this->mayUseMediaZone()) {
            echo new JsonResponse(null, Text::_('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN'), true);
            $app->close();
        }

        $mediaData = $this->input->post->get('mediaData', '', 'string');
        $type = $this->input->post->get('type', '', 'string');
        $index = $this->input->post->get('identifier', 0, 'string');
        $url = $this->input->post->get('url', '', 'string');
        $thumbnail = $this->input->post->get('thumbnail', '', 'string');

        if (empty($type)) {
            echo json_encode([
                'success' => false,
                'message' => 'Invalid request',
            ]);
            $app->close();
        }

        if ($type === 'url') {
            $result = $this->validateUrl($url);

            if (!$result['valid']) {
                echo new JsonResponse(null, $result['message'], true);
                $app->close();
            }
        }

        // Create clean media object
        $media = $this->prepareNewMediaObject(
            image :$mediaData,
            thumbnail: $thumbnail,
            type: $type,
            index: $index,
            url: $url,
        );

        // Render template with clean data. Pass the component layouts base path
        // explicitly: in a bare AJAX/JSON request Joomla does NOT auto-resolve the
        // component's layouts/ folder the way it does during a full page render, so
        // without this the layout silently renders to an empty string.
        $displayData = ['media' => $media];
        $layoutBase = JPATH_ADMINISTRATOR . '/components/com_alfa/layouts';
        $html = LayoutHelper::render('mediazone.item', $displayData, $layoutBase);

        echo json_encode([
            'success' => true,
            'html' => $html,
            'url' => $url,
            'http_code' => $result['http_code'] ?? null,
        ]);

        $app->close();
    }

    /**
     * Prepare new media object with all business logic
     *
     * @param string $image - Image source
     * @param string $type - 'drop' or 'picker'
     * @param int $index - File index
     * @return object - Clean media object
     * @since  1.0.0
     */
    protected function prepareNewMediaObject($image, $thumbnail, $type, $index, $url)
    {
        $params = ComponentHelper::getParams('com_alfa');

        // default url thumbnail
        $urlDefaultThumbnail = trim($params->get('media_url_thumbnail', ''));

        $media = new stdClass();

        // Generate unique ID for new media
        $media->id = 'new-' . uniqid();

        // Mark as new
        $media->isNew = true;

        $media->type = 'image';

        $thumbnail = !empty($thumbnail) ? $thumbnail : $image;

        // Set image source
        if ($type === 'drop') {
            // Blob URL from dropped file
            $media->path = $image; //blob:
            $media->thumbnail = $thumbnail;
            $media->source = $index;
        } elseif ($type === 'picker') {
            // Path from Joomla picker
            $media->path = $image;
            $media->thumbnail = $thumbnail;
            $media->source = 'picker:' . $image;
        } elseif ($type === 'url') {
            $media->path = $url;
            $media->thumbnail = empty($thumbnail) ? $urlDefaultThumbnail : $thumbnail;
            $media->source = $url;
            $media->type = 'url';
        }

        // Initialize empty values
        $media->alt = '';

        return $media;
    }

    /**
     * Normalise an existing media record for the picker: copy its path into the
     * 'source' property and mark it as not new.
     *
     * @param object $object The stored media record
     *
     * @return object The prepared media object
     * @since  1.0.0
     */
    public function prepareExistingMedia($object)
    {
        $media = new stdClass();

        $media = $object;
        $media->source = $object->path;
        $media->isNew = false;

        return $media;
    }

    /**
     * Validate a remote media URL: check the format, enforce an http/https
     * scheme, then issue a HEAD request and treat a 2xx/3xx status as accessible.
     *
     * @param string $url The URL to validate
     *
     * @return array Result with 'valid' (bool), 'message' (string) and 'http_code' (int|null)
     * @since  1.0.0
     */
    protected function validateUrl(string $url): array
    {
        $response = ['valid' => false, 'message' => '', 'http_code' => null];

        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            $response['message'] = 'Invalid URL format';
            return $response;
        }

        $uri = new Uri($url);
        $scheme = $uri->getScheme();
        if (!in_array(strtolower($scheme), ['http', 'https'], true)) {
            $response['message'] = 'Only HTTP/HTTPS URLs are allowed';
            return $response;
        }

        // SSRF guard: the target must resolve exclusively to public, routable
        // addresses, never loopback, link-local (cloud metadata) or private nets.
        if (!$this->isPublicHost($uri->getHost())) {
            $response['message'] = Text::_('COM_ALFA_MEDIA_ERROR_URL_NOT_ALLOWED');
            return $response;
        }

        try {
            // Do NOT follow redirects: isPublicHost() only vetted the initial host,
            // and a public host may 3xx-redirect to an internal target (loopback,
            // cloud metadata). Stopping at the redirect keeps the SSRF guard honest.
            $httpOptions = new \Joomla\Registry\Registry();
            $httpOptions->set('follow_location', false);
            $httpResponse = (new \Joomla\Http\HttpFactory())->getHttp($httpOptions)->head($url, [], 10);
            $valid = ($httpResponse->getStatusCode() >= 200 && $httpResponse->getStatusCode() < 400);

            $response['valid'] = $valid;
            $response['message'] = $valid ? 'URL is accessible' : "HTTP {$httpResponse->getStatusCode()}";
            $response['http_code'] = $httpResponse->getStatusCode();
            return $response;
        } catch (RuntimeException $e) {
            $response['valid'] = false;
            $response['message'] = 'Connection failed: ' . $e->getMessage();
            return $response;
        }
    }

    /**
     * May the current user work in a form that carries a MediaZone field: create or edit on
     * items, categories or manufacturers.
     *
     * The request does not say which form is open, so any of the three qualifies. The tile is
     * markup only; nothing is stored until that form itself is saved under its own permission.
     *
     *
     * @since  1.10.0
     */
    private function mayUseMediaZone(): bool
    {
        $user = $this->app->getIdentity();

        foreach (['items', 'categories', 'manufacturers'] as $section) {
            if (
                AlfaHelper::can(section: $section, action: 'edit', user: $user)
                || AlfaHelper::can(section: $section, action: 'create', user: $user)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a host resolves exclusively to public, routable IP addresses.
     *
     * SSRF guard for outbound requests: a host that resolves (in whole or in
     * part) to a loopback, link-local or private-range address is not public.
     *
     * @param string $host Hostname or IP literal from the target URL.
     *
     * @return bool True only when every resolved address is public.
     *
     * @since  1.3.1
     */
    private function isPublicHost(string $host): bool
    {
        if ($host === '') {
            return false;
        }

        $ips = [];

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
                $ips[] = $record['ip'] ?? $record['ipv6'] ?? null;
            }

            // Fallback when dns_get_record yields nothing usable.
            if (empty(array_filter($ips))) {
                $resolved = gethostbyname($host);
                $ips = $resolved !== $host ? [$resolved] : [];
            }
        }

        $ips = array_filter($ips);

        if (empty($ips)) {
            return false;
        }

        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }
}
