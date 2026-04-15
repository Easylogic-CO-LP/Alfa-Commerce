<?php
/**
 * @version    1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Administrator\Model;

// No direct access.
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\AdminModel;
use Joomla\CMS\User\UserFactoryInterface;

/**
 * User model.
 *
 * Persists com_alfa user records. The linked Joomla account is displayed
 * read-only; all credential management is done via the Joomla User Manager.
 *
 * @since  1.0.1
 */
class UserModel extends AdminModel
{
    /** @var string  @since 1.0.1 */
    protected $text_prefix = 'COM_ALFA';

    /** @var string  @since 1.0.1 */
    public $typeAlias = 'com_alfa.user';

    /** @var object|null  @since 1.0.1 */
    protected $item = null;

    /**
     * {@inheritdoc}
     * @since 1.0.1
     */
    public function getTable($name = 'User', $prefix = 'Administrator', $options = [])
    {
        return parent::getTable($name, $prefix, $options);
    }

    /**
     * {@inheritdoc}
     * @since 1.0.1
     */
    public function getForm($data = [], $loadData = true)
    {
        $form = $this->loadForm(
            'com_alfa.user',
            'user',
            ['control' => 'jform', 'load_data' => $loadData]
        );

        return $form ?: false;
    }

    /**
     * {@inheritdoc}
     * @since 1.0.1
     */
    protected function loadFormData()
    {
        $data = Factory::getApplication()->getUserState('com_alfa.edit.user.data', []);

        if (empty($data)) {
            $this->item = $this->item ?? $this->getItem();
            $data       = $this->item;
        }

        return $data;
    }

    /**
     * Returns a single record enriched with read-only display data from the
     * linked Joomla user (name, email, username) and a pre-built edit URL.
     *
     * The edit URL uses tmpl=component so the iframe only loads the edit form
     * without the full admin chrome around it.
     *
     * @param   int|null  $pk  Primary key.
     *
     * @return  object|false
     *
     * @since   1.0.1
     */
    public function getItem($pk = null)
    {
        $pk = (!empty($pk)) ? (int) $pk : (int) $this->getState($this->getName() . '.id');

        // Serve from cache when the same PK is requested more than once.
        if ($this->item !== null && isset($this->item->id) && (int) $this->item->id === $pk) {
            return $this->item;
        }

        $item = parent::getItem($pk);

        if (!$item) {
            return false;
        }

        // Hydrate read-only display fields from the linked Joomla user.
        if (!empty($item->id_user)) {
            $user = Factory::getContainer()
                ->get(UserFactoryInterface::class)
                ->loadUserById((int) $item->id_user);

            $item->joomla_name     = $user->name;
            $item->joomla_email    = $user->email;
            $item->joomla_username = $user->username;

            // tmpl=component strips admin chrome — only the edit form renders
            // inside the iframe. Route::_() is intentionally avoided here as
            // it can mangle core com_users admin URLs.
            $item->joomla_edit_url = 'index.php?option=com_users&task=user.edit'
    . '&id=' . (int) $item->id_user;
        }

        $this->item = $item;

        return $item;
    }
}