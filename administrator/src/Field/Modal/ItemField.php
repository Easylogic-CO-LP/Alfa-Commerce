<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field\Modal;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Model\ItemModel;
use Joomla\CMS\Form\Field\ModalSelectField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use SimpleXMLElement;

/**
 * One product, picked in a modal from the products list (type="Modal_Item"): Joomla's modal select field, as
 * com_content's article picker is. The modal is the items list's layout=modal (administrator/tmpl/items/modal.php), with
 * its own search, filters and ordering kept apart from the list's own (modal=1). Select only: no new or edit button. The
 * product shows as the refusals name it ({@see ItemModel::nameForStaff()}): its name in the staff member's language,
 * else its number.
 *
 * @since  2.3.0
 */
class ItemField extends ModalSelectField
{
    /**
     * The form field type.
     *
     * @var string
     *
     * @since  2.3.0
     */
    protected $type = 'Modal_Item';

    /**
     * Method to attach a Form object to the field.
     *
     * @param SimpleXMLElement $element The SimpleXMLElement object representing the `<field>` tag for the form field object.
     * @param mixed $value The form field value to validate.
     * @param string $group The field name group control value.
     *
     * @return bool True on success.
     *
     * @since  2.3.0
     */
    public function setup(SimpleXMLElement $element, $value, $group = null)
    {
        $result = parent::setup($element, $value, $group);

        if (!$result) {
            return $result;
        }

        $select = (new Uri())->setPath(Uri::base(true) . '/index.php');
        $select->setQuery([
            'option' => 'com_alfa',
            'view' => 'items',
            'layout' => 'modal',
            'tmpl' => 'component',
            'modal' => 1,
            Session::getFormToken() => 1,
        ]);

        $this->urls['select'] = (string) $select;
        $this->modalTitles['select'] = Text::_('COM_ALFA_ITEMS_SELECT_A_PRODUCT');
        $this->hint = $this->hint ?: Text::_('COM_ALFA_ITEMS_SELECT_A_PRODUCT');

        return $result;
    }

    /**
     * The chosen product's name for the field.
     *
     * @return string
     *
     * @since  2.3.0
     */
    protected function getValueTitle()
    {
        $id = (int) $this->value;

        return $id > 0 ? ItemModel::nameForStaff(itemId: $id) : '';
    }
}
