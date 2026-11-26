<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Throwable;

/**
 * The language an order was placed in: the tag of `#__alfa_orders.id_language`, a `#__languages` id, and the site's
 * default content language when the order names none or names one that is gone. Never the language of whoever runs
 * the request, which in the back office is the operator's. The one answer for everything a customer reads about an
 * order: its e-mails, and the links they carry, which name the language so the page they open is the one of the
 * order's menu items.
 *
 * @since  2.3.0
 */
final class OrderLanguage
{
    /**
     * The language tag of an order.
     *
     * @param int $idLanguage The order's id_language.
     *
     * @return string A tag such as 'en-GB'.
     *
     * @since  2.3.0
     */
    public static function tagOf(int $idLanguage): string
    {
        if ($idLanguage > 0) {
            try {
                $db = Factory::getContainer()->get(DatabaseInterface::class);
                $query = $db->getQuery(true)
                    ->select($db->quoteName('lang_code'))
                    ->from($db->quoteName('#__languages'))
                    ->where($db->quoteName('lang_id') . ' = :id')
                    ->bind(':id', $idLanguage, ParameterType::INTEGER);
                $tag = (string) ($db->setQuery($query)->loadResult() ?? '');

                if ($tag !== '') {
                    return $tag;
                }
            } catch (Throwable $e) {
                // The site's default stands in for a language table that cannot be read.
            }
        }

        return MultilingualHelper::getDefaultLanguageCode();
    }

    /**
     * The language of an order's row, for a site link: '&lang=<tag>'.
     *
     * @param object $order The order's row (id_language).
     *
     * @since  2.3.0
     */
    public static function linkSuffix(object $order): string
    {
        return '&lang=' . self::tagOf((int) ($order->id_language ?? 0));
    }
}
