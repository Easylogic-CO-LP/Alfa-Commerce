<?php

namespace Alfa\Component\Alfa\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\MVC\Controller\FormController;
use Joomla\CMS\Response\JsonResponse;
use Joomla\CMS\Uri\Uri;
use RuntimeException;
use stdClass;

class MediaController extends FormController
{
    /**
     * Media Controller - AJAX getSource Method
     * Handles business logic and prepares data for template
     */

    public function getSource()
    {
        $app = Factory::getApplication();

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

        // Render template with clean data
        $displayData = ['media' => $media];
        $html = LayoutHelper::render('mediazone.dropmedia', $displayData);

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
     */
    protected function prepareNewMediaObject($image, $thumbnail, $type, $index, $url)
    {
        $params = ComponentHelper::getParams('com_alfa');

        // default url thumbnail
        $urlDefaultThumbnail = trim($params->get('url_thumbnail', ''));

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

    public function prepareExistingMedia($object)
    {
        $media = new stdClass();

        $media = $object;
        $media->source = $object->path;
        $media->isNew = false;

        return $media;
    }

    public function validateUrl(string $url): array
    {
        $response = ['valid' => false, 'message' => '', 'http_code' => null];

        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            $response['message'] = 'Invalid URL format';
            return $response;
        }

        $scheme = (new Uri($url))->getScheme();
        if (!in_array(strtolower($scheme), ['http', 'https'], true)) {
            $response['message'] = 'Only HTTP/HTTPS URLs are allowed';
            return $response;
        }

        try {
            $httpResponse = (new \Joomla\Http\HttpFactory())->getHttp()->head($url, [], 10);
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
}
