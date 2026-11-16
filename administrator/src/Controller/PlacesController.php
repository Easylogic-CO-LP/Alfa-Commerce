<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\AdminController;
use Joomla\CMS\Router\Route;
use Throwable;

/**
 * Places list controller class.
 *
 * @since  1.0.0
 */
class PlacesController extends AdminController
{
    /**
     * Proxy for getModel.
     *
     * @param string $name Optional. Model name
     * @param string $prefix Optional. Class prefix
     * @param array $config Optional. Configuration array for model
     *
     * @return object The Model
     *
     * @since  1.0.0
     */
    public function getModel($name = 'Place', $prefix = 'Administrator', $config = ['ignore_request' => true]): object
    {
        return parent::getModel($name, $prefix, $config);
    }

    /**
     * Add, unpublished, the places Alfa Commerce ships inside the selected countries (Greece's regions and Mount Athos;
     * the Canary Islands, Ceuta and Melilla inside Spain), the existing shop's way to them (2.2.1): an update never adds
     * them. One message per selected place says what was added, or why nothing was.
     *
     *
     * @since  2.2.1
     */
    public function addSubdivisions(): void
    {
        $this->checkToken();

        $this->setRedirect(Route::_('index.php?option=com_alfa&view=places', false));

        if (!AlfaHelper::can(section: 'places', action: 'create', user: $this->app->getIdentity())) {
            $this->setMessage(Text::_('JLIB_APPLICATION_ERROR_CREATE_RECORD_NOT_PERMITTED'), 'error');

            return;
        }

        $pks = array_values(array_filter(array_map('intval', (array) $this->input->get('cid', [], 'array'))));

        if ($pks === []) {
            $this->setMessage(Text::_('JGLOBAL_NO_ITEM_SELECTED'), 'warning');

            return;
        }

        try {
            $summary = $this->getModel()->addSubdivisions($pks);
        } catch (Throwable $e) {
            $this->setMessage($e->getMessage(), 'error');

            return;
        }

        foreach ($summary as $country) {
            if ($country['outcome'] === 'added') {
                $this->app->enqueueMessage(Text::sprintf(
                    'COM_ALFA_PLACES_SUBDIVISIONS_ADDED',
                    $country['name'],
                    \count($country['added']),
                    implode(', ', $country['added']),
                ));
            } elseif ($country['outcome'] === 'present') {
                $this->app->enqueueMessage(Text::sprintf('COM_ALFA_PLACES_SUBDIVISIONS_PRESENT', $country['name']));
            } else {
                $this->app->enqueueMessage(Text::sprintf('COM_ALFA_PLACES_SUBDIVISIONS_NONE', $country['name']), 'warning');
            }
        }
    }
}
