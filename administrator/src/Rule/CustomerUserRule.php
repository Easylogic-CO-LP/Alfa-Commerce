<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Rule;

defined('_JEXEC') or die;

use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormRule;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseAwareInterface;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;
use SimpleXMLElement;
use UnexpectedValueException;

/**
 * A customer record names one existing Joomla user, and no other customer record names the same user.
 *
 * A form RULE, not model code: the REST create and update of a customer and the administrator's customer form
 * reach the value through Form::validate(), so one rule refuses for every writer, with the sentence that says why.
 * Alfa Sync makes the record of a new Joomla user itself, and only when the user has none.
 *
 * @since  2.2.1
 */
class CustomerUserRule extends FormRule implements DatabaseAwareInterface
{
    use DatabaseAwareTrait;

    /**
     * @param SimpleXMLElement $element The field element.
     * @param mixed $value The posted Joomla user id.
     * @param string|null $group The field group.
     * @param Registry|null $input The whole posted data: its id is the customer record being saved (0 for a new one).
     * @param Form|null $form The form.
     *
     * @return bool True when the value names a Joomla user that no other customer record names.
     *
     * @throws UnexpectedValueException With a translated sentence the client and the operator read.
     *
     * @since  2.2.1
     */
    public function test(SimpleXMLElement $element, $value, $group = null, ?Registry $input = null, ?Form $form = null)
    {
        // Emptiness is the required= attribute's business, not this rule's.
        if ($value === null || $value === '') {
            return true;
        }

        $userId = \is_scalar($value) && ctype_digit((string) $value) ? (int) $value : 0;
        $db = $this->getDatabase();

        $exists = (int) $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__users'))
                ->where($db->quoteName('id') . ' = :user')
                ->bind(':user', $userId, ParameterType::INTEGER),
        )->loadResult();

        if ($userId <= 0 || $exists === 0) {
            throw new UnexpectedValueException(Text::sprintf('COM_ALFA_USER_ERROR_NO_JOOMLA_USER', $userId));
        }

        $recordId = (int) ($input?->get('id') ?? 0);

        $other = (int) $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName('#__alfa_users'))
                ->where($db->quoteName('id_user') . ' = :user')
                ->where($db->quoteName('id') . ' <> :record')
                ->bind(':user', $userId, ParameterType::INTEGER)
                ->bind(':record', $recordId, ParameterType::INTEGER),
            0,
            1,
        )->loadResult();

        if ($other > 0) {
            throw new UnexpectedValueException(Text::sprintf('COM_ALFA_USER_ERROR_ALREADY_CUSTOMER', $userId, $other));
        }

        return true;
    }
}
