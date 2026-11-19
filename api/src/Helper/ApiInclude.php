<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\Helper;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\RequestScalar;
use Joomla\CMS\Factory;

/**
 * What a read asks to be included beside its fields (2.2.2): the include parameter, a comma-separated list
 * (include=translations). Today it names one thing, translations: every content language's texts of a translated
 * record, on a list row and on a single record alike; without it a read carries the texts in one language only
 * (lang, else the shop's default content language).
 *
 * @since  2.2.2
 */
final class ApiInclude
{
    /**
     * The translations object of a translated record.
     *
     * @since  2.2.2
     */
    public const TRANSLATIONS = 'translations';

    /**
     * Whether the request's include parameter names this.
     *
     * @param string $name What is asked for, one of the constants of this class.
     *
     * @since  2.2.2
     */
    public static function asks(string $name): bool
    {
        $include = RequestScalar::string(Factory::getApplication()->getInput(), 'include', '');

        return \in_array($name, array_map('trim', explode(',', strtolower($include))), true);
    }
}
