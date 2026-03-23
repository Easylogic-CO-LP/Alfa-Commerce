<?php

namespace Alfa\Component\Alfa\Administrator\Field;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Controller\MediaController;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Layout\LayoutHelper;

/**
 * Drop Media Field
 *
 * @since  1.0.0
 */
class MediaZoneField extends FormField
{
    /**
     * Field type
     *
     * @var string
     */
    protected $type = 'MediaZone';

    /**
     * Get the field input markup
     *
     * @return string
     * @since   1.0.0
     */
    private const MIME_EXTENSION_MAP = [
        // Images
        'image/jpeg' => ['images', ['jpg', 'jpeg']],
        'image/gif' => ['images', ['gif']],
        'image/png' => ['images', ['png']],
        'image/bmp' => ['images', ['bmp']],
        'image/webp' => ['images', ['webp']],
        'image/avif' => ['images', ['avif']],
        // Audios
        'audio/mpeg' => ['audios', ['mp3']],
        'audio/ogg' => ['audios', ['ogg']],
        'audio/wav' => ['audios', ['wav']],
        'audio/flac' => ['audios', ['flac']],
        // Videos
        'video/mp4' => ['videos', ['mp4']],
        'video/webm' => ['videos', ['webm']],
        'video/ogg' => ['videos', ['ogv']],
        // Documents
        'application/pdf' => ['documents', ['pdf']],
    ];

    private const DEFAULT_IMAGE_EXTENSIONS = [];

    /**
     * Get the field input markup
     *
     * @return string
     * @since   1.0.0
     */
    protected function getInput()
    {
        $multiple = $this->element['multiple'] ? $this->element['multiple'] == 'true' || $this->element['multiple'] == '1' : false;
        $allowedMediaTypes = $this->element['types'];

        $params = ComponentHelper::getParams('com_alfa');
        $medias = $this->prepareMediaObjects($this->value);

        $allowedMimes = $params->get('media_mime');
        $supportedExtensions = $this->getSupportedExtensions($allowedMimes);

        // Pass prepared data to layout
        return LayoutHelper::render('mediazone.dropmedias', [
            'data' => $medias,
            'mimes' => $allowedMimes,
            'supportedExtensions' => $supportedExtensions,
            'fieldId' => $this->id,
            'fieldName' => $this->name,
            'multiple' => $multiple,
            'allowedTypes' => $allowedMediaTypes,
        ]);
    }

    /**
     * Get supported file extensions grouped by media type
     *
     * @param mixed $mimes Allowed MIME types from component config
     *
     * @return array{images: string[], audios: string[], videos: string[], documents: string[]}
     * @since   1.0.0
     */
    private function getSupportedExtensions($mimes): array
    {
        $result = ['images' => [], 'audios' => [], 'videos' => [], 'documents' => []];

        if (!empty($mimes) && is_array($mimes)) {
            foreach ($mimes as $mime) {
                if (isset(self::MIME_EXTENSION_MAP[$mime])) {
                    [$type, $extensions] = self::MIME_EXTENSION_MAP[$mime];
                    array_push($result[$type], ...$extensions);
                }
            }
        }

        if (empty($result['images'])) {
            $result['images'] = self::DEFAULT_IMAGE_EXTENSIONS;
        }

        return $result;
    }

    /**
     * Prepare media objects from database records
     * Transforms raw DB data into display-ready objects
     *
     * @param mixed $value Field value (array of DB records or null)
     *
     * @return array Array of prepared media objects
     * @since   1.0.0
     */
    protected function prepareMediaObjects($value)
    {
        // No value means no media (new item or no media added)
        if (empty($value) || !is_array($value)) {
            return [];
        }

        // Use controller to prepare each record
        // This ensures consistency across the entire application
        $controller = new MediaController();
        $preparedMedias = [];

        foreach ($value as $record) {
            $preparedMedias[] = $controller->prepareExistingMedia($record);
        }

        return $preparedMedias;
    }
}
