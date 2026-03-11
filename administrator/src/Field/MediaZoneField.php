<?php
namespace Alfa\Component\Alfa\Administrator\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Layout\LayoutHelper;
use Alfa\Component\Alfa\Administrator\Controller\MediaController;

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
     * @return  string
     * @since   1.0.0
     */
    protected function getInput()
    {
        $multiple = $this->element['multiple'] ? $this->element['multiple'] == 'true' || $this->element['multiple'] == '1' : false;
        $allowedMediaTypes = $this->element['types'];

        $params = ComponentHelper::getParams('com_alfa');
        $medias = $this->prepareMediaObjects($this->value);

        $allowedMimes = $params->get('media_mime');

        // Pass prepared data to layout
        return LayoutHelper::render('mediazone.dropmedias', [
            'data' => $medias,
            'mimes' => $allowedMimes,
            'fieldId' => $this->id,
            'fieldName' => $this->name,
            'multiple' => $multiple,
            'allowedTypes' => $allowedMediaTypes,
        ]);
    }

    /**
     * Prepare media objects from database records
     * Transforms raw DB data into display-ready objects
     *
     * @param   mixed  $value  Field value (array of DB records or null)
     *
     * @return  array  Array of prepared media objects
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

