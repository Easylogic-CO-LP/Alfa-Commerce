<?php

/**
 * @version    1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Site\Model;

// No direct access.
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MediaHelper;
use Alfa\Component\Alfa\Site\Helper\AlfaHelper;
use Alfa\Component\Alfa\Site\Service\Pricing;
use Exception;
use Joomla\CMS\Factory;
//use Alfa\Component\Alfa\Site\Helper\PriceCalculator;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\ItemModel as BaseItemModel;
use Joomla\Database\ParameterType;

/**
 * Alfa model.
 *
 * @since  1.0.1
 */
class ItemModel extends BaseItemModel
{
    /**
     * Model context string.
     *
     * @var string
     */
    protected $_context = 'com_alfa.item';

    /**
     * Method to auto-populate the model state.
     *
     * Note. Calling getState in this method will result in recursion.
     *
     *
     * @throws Exception
     * @since  1.0.1
     */
    protected function populateState(): void
    {
        $app = Factory::getApplication();

        // Get the item ID from the request
        $id = $app->input->getInt('id', 0);
        $this->setState('item.id', $id);
    }

    /**
     * Method to get an object.
     *
     * @param int|null $pk The id of the object to get.
     *
     * @return mixed Object on success, false on failure.
     *
     * @throws Exception
     * @since   1.0.1
     */
    public function getItem($pk = null): mixed
    {
        $user = $this->getCurrentUser();
        $pk = (int) ($pk ?: $this->getState('item.id'));

        if ($this->_item === null) {
            $this->_item = [];
        }

        if (!isset($this->_item[$pk])) {
            $db = $this->getDatabase();
            $query = $db->getQuery(true);

            $query->select(
                $this->getState(
                    'item.select',
                    ['a.*'],
                ),
            )
                ->from($db->quoteName('#__alfa_items', 'a'))
                ->where($db->quoteName('a.id') . ' = :pk')
                ->bind(':pk', $pk, ParameterType::INTEGER);

            $db->setQuery($query);
            $data = $db->loadObject();

            if (!$data) {
                throw new Exception(Text::_('COM_ALFA_ERROR_ITEM_NOT_FOUND'), 404);
            }

            // Setting correct stock action settings
            $settings = AlfaHelper::getGeneralSettings();

            if ($data->stock_action == -1) {
                $data->stock_action = $settings->get('stock_action');
                $data->stock_low_message = $settings->get('stock_low_message');
                $data->stock_zero_message = $settings->get('stock_zero_message');
            }

            if (empty($data->stock_low_message)) {
                $data->stock_low_message = $settings->get('stock_low_message');
            }

            if (empty($data->stock_zero_message)) {
                $data->stock_zero_message = $settings->get('stock_zero_message');
            }

            // Get categories
            $categories = $this->getItemCategories($pk);
            $categoryIDs = array_column($categories, 'id');
            $categoryMedia = !empty($categoryIDs)
                ? MediaHelper::getMediaData(origin: 'category', itemIDs: $categoryIDs)
                : [];

            $data->categories = [];

            foreach ($categories as $category) {
                $data->categories[$category['id']] = [
                    'name' => $category['name'],
                    'media' => $categoryMedia[$category['id']] ?? [],
                ];
            }

            // Get manufacturers
            $manufacturers = $this->getItemManufacturers($pk);
            $manufacturerIDs = array_column($manufacturers, 'id');
            $manufacturerMedia = !empty($manufacturerIDs)
                ? MediaHelper::getMediaData(origin: 'manufacturer', itemIDs: $manufacturerIDs)
                : [];

            $data->manufacturers = [];

            foreach ($manufacturers as $manufacturer) {
                $data->manufacturers[$manufacturer['id']] = [
                    'name' => $manufacturer['name'],
                    'media' => $manufacturerMedia[$manufacturer['id']] ?? [],
                ];
            }

            // Calculate the dynamic price
            $quantity = (int) $this->getState('quantity', 1);
            $userGroupId = 0;
            $currencyId = 0;
            //          $priceCalculator = new PriceCalculator($pk, $quantity, $userGroupId, $currencyId);
            //          $data->price     = $priceCalculator->calculatePrice();

            $calculator = new Pricing\PriceCalculator();
            $context = Pricing\PriceContext::fromSession();
            $priceResult = $calculator->calculate($pk, $quantity, $context);
            $data->price = $priceResult; //->toArray();

            // Get payment and shipment methods
            $categoryIDs = empty($data->categories) ? [] : array_keys($data->categories);
            $manufacturerIDs = empty($data->manufacturers) ? [] : array_keys($data->manufacturers);

            $data->payment_methods = AlfaHelper::getFilteredMethods(
                $categoryIDs,
                $manufacturerIDs,
                $user->groups,
                $user->id,
                'payment',
            );

            $data->shipment_methods = AlfaHelper::getFilteredMethods(
                $categoryIDs,
                $manufacturerIDs,
                $user->groups,
                $user->id,
                'shipment',
            );

            $data->medias = MediaHelper::getMediaData(
                origin: 'item',
                itemIDs: $data->id,
            );

            $this->_item[$pk] = $data;
        }

        return $this->_item[$pk];
    }

    /**
     * Get item categories
     *
     * @param int $pk Item ID
     *
     *
     * @since   1.0.1
     */
    protected function getItemCategories(int $pk): array
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true);

        $query->select([
            $db->quoteName('c.id'),
            $db->quoteName('c.name'),
        ])
            ->from($db->quoteName('#__alfa_categories', 'c'))
            ->join(
                'INNER',
                $db->quoteName('#__alfa_items_categories', 'ic'),
                $db->quoteName('c.id') . ' = ' . $db->quoteName('ic.category_id'),
            )
            ->where($db->quoteName('ic.item_id') . ' = :pk')
            ->bind(':pk', $pk, ParameterType::INTEGER);

        $db->setQuery($query);

        return $db->loadAssocList() ?: [];
    }

    /**
     * Get item manufacturers
     *
     * @param int $pk Item ID
     *
     *
     * @since   1.0.1
     */
    protected function getItemManufacturers(int $pk): array
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true);

        $query->select([
            $db->quoteName('m.id'),
            $db->quoteName('m.name'),
        ])
            ->from($db->quoteName('#__alfa_manufacturers', 'm'))
            ->join(
                'INNER',
                $db->quoteName('#__alfa_items_manufacturers', 'im'),
                $db->quoteName('m.id') . ' = ' . $db->quoteName('im.manufacturer_id'),
            )
            ->where($db->quoteName('im.item_id') . ' = :pk')
            ->bind(':pk', $pk, ParameterType::INTEGER);

        $db->setQuery($query);

        return $db->loadAssocList() ?: [];
    }
}
