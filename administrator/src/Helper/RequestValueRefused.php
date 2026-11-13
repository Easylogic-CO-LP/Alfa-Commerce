<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Throwable;
use Tobscure\JsonApi\Exception\InvalidParameterException;

/**
 * A request value that is not the single value its reader expects: a list (name[]=...) where a number, a word or a
 * text is read.
 *
 * Answered 400 with a neutral sentence (2.2.1): the storefront's error page carries no exception text of
 * the code that would have received the value, so no server path, and the REST API names the parameter, as Joomla's
 * API answers an invalid parameter.
 *
 * @since  2.2.1
 */
final class RequestValueRefused extends InvalidParameterException
{
    /**
     * @param string $name The request variable.
     *
     * @since  2.2.1
     */
    public function __construct(string $name)
    {
        // The storefront error page is rendered in requests that never load the administrator strings.
        try {
            Factory::getApplication()->getLanguage()->load('com_alfa', JPATH_ADMINISTRATOR);
        } catch (Throwable) {
            // No application: the key is answered as it is.
        }

        parent::__construct(Text::_('COM_ALFA_REQUEST_VALUE_REFUSED'), 400, null, $name);
    }
}
