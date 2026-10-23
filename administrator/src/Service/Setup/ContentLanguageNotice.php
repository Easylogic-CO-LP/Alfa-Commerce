<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Setup;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Throwable;

/**
 * Tells the administrators when the site language is not a published content language.
 *
 * Untranslated products, categories, manufacturers and their URLs are read in the default
 * content language ({@see MultilingualHelper::getDefaultLanguageTag()}). When the site language
 * set in the Language Manager is missing or has no published content language, they are read in
 * a fallback instead, and this notice says which setting is wrong and how to fix it. It stands
 * until the configuration is valid again, then it is cleared.
 *
 * Run from the bell's background refresh ({@see \Alfa\Component\Alfa\Administrator\Controller\NotificationsController::panel()}),
 * never on a page render: two indexed reads while the configuration is valid. The notice is
 * raised once, with one log line, and not again while it stands.
 *
 * @since  2.1.1
 */
final class ContentLanguageNotice
{
    /**
     * The notice's dedup key.
     *
     * @var string
     * @since  2.1.1
     */
    public const KEY = 'alfa.content_language';

    /**
     * Raise the notice while the configuration is invalid, clear it once it is valid.
     *
     * Advisory: a failure is logged and never stops the request.
     *
     * @since  2.1.1
     */
    public static function sync(): void
    {
        try {
            $problem = MultilingualHelper::contentLanguageProblem();

            if ($problem === null) {
                if (NotificationHelper::isOpen(dedupKey: self::KEY)) {
                    NotificationHelper::clear(dedupKey: self::KEY);
                }

                return;
            }

            if (NotificationHelper::isOpen(dedupKey: self::KEY)) {
                return;
            }

            Factory::getApplication()->getLanguage()->load('com_alfa', JPATH_ADMINISTRATOR);

            if (!$problem['published']) {
                $message = Text::sprintf('COM_ALFA_NOTIFY_CONTENT_LANGUAGE_MSG_NONE', $problem['used']);
            } elseif ($problem['configured'] === '') {
                $message = Text::sprintf('COM_ALFA_NOTIFY_CONTENT_LANGUAGE_MSG_UNSET', $problem['used']);
            } else {
                $message = Text::sprintf('COM_ALFA_NOTIFY_CONTENT_LANGUAGE_MSG', $problem['configured'], $problem['used']);
            }

            // It cannot be waved away while the setting is wrong; a re-raise after a clear
            // opens it again as new.
            NotificationHelper::push(
                dedupKey: self::KEY,
                title:    Text::_('COM_ALFA_NOTIFY_CONTENT_LANGUAGE_TITLE'),
                options:  [
                    'group' => Text::_('COM_ALFA_NOTIFY_GROUP_SETUP'),
                    'severity' => 'warning',
                    'message' => $message,
                    'url' => 'index.php?option=com_languages&view=languages',
                    'dismissible' => false,
                    'constant' => true,
                    'link' => ['action' => 'core.manage', 'asset' => 'com_languages'],
                ],
            );

            Log::add(
                'The site language "' . ($problem['configured'] !== '' ? $problem['configured'] : 'none')
                . '" is not a published content language: untranslated content is read in "'
                . $problem['used'] . '" until it is fixed.',
                Log::WARNING,
                'com_alfa.setup',
            );
        } catch (Throwable $e) {
            Log::add('The content language check failed: ' . $e->getMessage(), Log::WARNING, 'com_alfa.setup');
        }
    }
}
