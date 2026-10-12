<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Model;

use Joomla\CMS\Table\Table;

\defined('_JEXEC') or die;

/**
 * The order builder's form seam.
 *
 * The builder reads its cart through {@see CartModel} and mutates it through the
 * B1B.1c request seam; this model exists only to carry the `com_alfa.cart` form
 * so a plugin's onAlfaAdminForm fieldset becomes a builder tab, exactly as the
 * Order and Item screens gain their contributed tabs. It saves no record (the
 * form has no submit), so it takes {@see AlfaAdminModel}'s hooks (which is what
 * fires onAlfaAdminForm for entity 'cart') and adds nothing but the form load.
 *
 * @since  1.10.0
 */
class CartBuilderModel extends AlfaAdminModel
{
    /**
     * @var string The language string prefix.
     * @since  1.10.0
     */
    protected $text_prefix = 'COM_ALFA';

    /**
     * @var string The entity a plugin targets with $context->targets('cart').
     * @since  1.10.0
     */
    protected string $alfaEntity = 'cart';

    /**
     * The builder form: loaded by the entity name so preprocessForm dispatches
     * onAlfaAdminForm for 'cart', with no record data to bind (there is none).
     *
     * @param array $data Ignored; the builder binds no record.
     * @param bool $loadData Ignored; always loaded without data.
     *
     * @return \Joomla\CMS\Form\Form|false
     *
     * @since  1.10.0
     */
    public function getForm($data = [], $loadData = true)
    {
        $form = $this->loadForm('com_alfa.cart', 'cart', ['control' => 'jform', 'load_data' => false]);

        return empty($form) ? false : $form;
    }

    /**
     * The cart table, for the rare hook that asks; the builder never saves through it.
     *
     * @param string $name The table name.
     * @param string $prefix The class prefix.
     * @param array $options Configuration.
     *
     *
     * @since  1.10.0
     */
    public function getTable($name = 'Cart', $prefix = 'Administrator', $options = []): Table
    {
        return parent::getTable($name, $prefix, $options);
    }
}
