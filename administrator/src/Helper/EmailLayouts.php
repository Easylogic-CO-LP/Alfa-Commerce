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
use Joomla\CMS\Layout\FileLayout;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * Where an e-mail's layouts are found (2.2.1): the default administrator template's overrides
 * (administrator/templates/<template>/html/layouts/com_alfa, then the template it inherits from), then the
 * component's own layouts, whatever application sends the mail. Joomla's own resolution follows the template of the
 * application that renders, so a mail sent from the storefront, the REST API or the console never found an override
 * the order status picker offered from an administrator template. The picker lists from these same folders, and the
 * e-mails render through them.
 *
 * @since  2.2.1
 */
final class EmailLayouts
{
    /**
     * The folders, highest priority first, for the request.
     *
     * @var string[]|null
     * @since  2.2.1
     */
    private static ?array $paths = null;

    /**
     * The folders an e-mail layout is looked for in, highest priority first: the default administrator template's
     * html/layouts/com_alfa, its parent's, then the component's layouts.
     *
     * @return string[]
     *
     * @since  2.2.1
     */
    public static function includePaths(): array
    {
        if (self::$paths !== null) {
            return self::$paths;
        }

        $paths = [];

        foreach (self::administratorTemplates() as $template) {
            $paths[] = JPATH_ADMINISTRATOR . '/templates/' . $template . '/html/layouts/com_alfa';
        }

        $paths[] = JPATH_ADMINISTRATOR . '/components/com_alfa/layouts';

        return self::$paths = $paths;
    }

    /**
     * Render an e-mail layout from those folders.
     *
     * @param string $layoutId The layout, in dot notation (emails.order.default).
     * @param array $displayData What the layout reads.
     *
     * @since  2.2.1
     */
    public static function render(string $layoutId, array $displayData): string
    {
        $layout = new FileLayout($layoutId, null, ['component' => 'com_alfa', 'client' => 1]);
        $layout->setIncludePaths(self::includePaths());

        return (string) $layout->render($displayData);
    }

    /**
     * The default administrator template style's template, then the parent it inherits from.
     *
     * @return string[] Template element names; empty when no default style is set.
     *
     * @since  2.2.1
     */
    private static function administratorTemplates(): array
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $clientId = 1;
        $home = '1';

        $style = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['template', 'parent']))
                ->from($db->quoteName('#__template_styles'))
                ->where($db->quoteName('client_id') . ' = :clientId')
                ->where($db->quoteName('home') . ' = :home')
                ->bind(':clientId', $clientId, ParameterType::INTEGER)
                ->bind(':home', $home, ParameterType::STRING),
            0,
            1,
        )->loadObject();

        return $style === null ? [] : array_values(array_filter([(string) $style->template, (string) $style->parent]));
    }
}
