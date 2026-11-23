<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Setup;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\ErrorText;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Throwable;

/**
 * The Joomla libraries Alfa Commerce installs with itself (alfa.xml), by the feature that needs them, and whether a
 * feature can use them here.
 *
 * A library can be used when Joomla holds it as an enabled library (its row in Extensions: Manage, by its library name)
 * and its class loads. Joomla's autoloader registers a library's namespace whether the library is enabled or not, so a
 * class that loads is not enough: a library someone disabled counts as one that is missing. A feature asks at the point
 * of use and refuses with a plain message while one of its libraries cannot be used; the notification centre says so
 * to the administrators ({@see InformationNotices}).
 *
 * @since  2.2.2
 */
final class BundledLibraries
{
    /**
     * The libraries the PDF of a sales document needs: dompdf and the four libraries it builds on, each by its library
     * name with one class of it.
     *
     * @var array<string, class-string>
     *
     * @since  2.2.2
     */
    public const PDF = [
        'dompdf' => \Dompdf\Dompdf::class,
        'php-font-lib' => \FontLib\Font::class,
        'php-svg-lib' => \Svg\Document::class,
        'masterminds/html5' => \Masterminds\HTML5::class,
        'sabberworm/php-css-parser' => \Sabberworm\CSS\Parser::class,
    ];

    /**
     * The library a phone number is checked and stored with (the phone field, Alfa Fields - Phone, and the
     * administrator's telephone fields): libphonenumber, by its library name with the class they use. The locale data
     * installed beside it (giggsey/locale) is read only by its carrier and place names, which Alfa Commerce does not
     * use, so it is not asked for.
     *
     * @var array<string, class-string>
     *
     * @since  2.2.2
     */
    public const PHONE = [
        'libphonenumber' => \libphonenumber\PhoneNumberUtil::class,
    ];

    /**
     * The libraries of a set that cannot be used here: no enabled library of that name in Joomla's extensions, or its
     * class does not load. One indexed read of #__extensions; not kept between calls, so a library enabled or disabled
     * within the request is read as it now is.
     *
     * @param array<string, class-string> $libraries {@see self::PDF} or {@see self::PHONE}.
     *
     * @return list<string> Their library names, in the set's order; empty when every one can be used.
     *
     * @since  2.2.2
     */
    public static function unavailable(array $libraries): array
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $type = 'library';
        $enabled = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('element'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = :type')
                ->where($db->quoteName('enabled') . ' = 1')
                ->whereIn($db->quoteName('element'), array_keys($libraries), ParameterType::STRING)
                ->bind(':type', $type, ParameterType::STRING),
        )->loadColumn() ?: [];
        $enabled = array_flip(array_map('strval', $enabled));
        $unavailable = [];

        foreach ($libraries as $name => $class) {
            if (!isset($enabled[$name]) || !class_exists($class)) {
                $unavailable[] = $name;
            }
        }

        return $unavailable;
    }

    /**
     * Whether every library of a set can be used here: {@see self::unavailable()} names none. The point of use asks
     * this one: a reading that fails is logged and answered as not available, so the feature refuses with its own
     * plain message rather than a database error.
     *
     * @param array<string, class-string> $libraries {@see self::PDF} or {@see self::PHONE}.
     *
     * @since  2.2.2
     */
    public static function available(array $libraries): bool
    {
        try {
            return self::unavailable(libraries: $libraries) === [];
        } catch (Throwable $e) {
            Log::add('The bundled libraries could not be read (' . implode(', ', array_keys($libraries)) . '): ' . $e::class . ': ' . ErrorText::of($e), Log::WARNING, 'com_alfa');

            return false;
        }
    }
}
