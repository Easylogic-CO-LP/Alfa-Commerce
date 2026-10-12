<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Menu;

\defined('_JEXEC') or die;

use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Factory;
use Joomla\CMS\Layout\FileLayout;
use Joomla\Event\Event;
use Throwable;

/**
 * Places the Alfa menu on EVERY com_alfa admin page, once, without touching a single view.
 *
 * Registered on `onAfterDispatch` by {@see \Alfa\Component\Alfa\Administrator\Extension\AlfaComponent::boot()},
 * it renders the sidebar markup and prepends it to the component's output; a small script
 * then relocates it into Joomla's native #sidebarmenu. The menu is skipped where it would
 * not show anyway: non-admin, another component, an edit screen (`hidemainmenu`, which
 * hides the whole sidebar), or a non-HTML document, so it costs nothing there.
 *
 * @since  1.7.0
 */
final class MenuInjector
{
    /**
     * Render and inject the menu after the component has produced its output.
     *
     * @param Event $event The onAfterDispatch event (unused).
     *
     *
     * @since  1.7.0
     */
    public static function onAfterDispatch(Event $event): void
    {
        try {
            $app = Factory::getApplication();

            if (!$app->isClient('administrator')) {
                return;
            }

            $input = $app->getInput();

            if ($input->getCmd('option') !== 'com_alfa' || $input->getBool('hidemainmenu')) {
                return;
            }

            $doc = $app->getDocument();

            if (!$doc instanceof HtmlDocument) {
                return;
            }

            $groups = (new MenuBuilder())->build($input, $app->getIdentity());

            if ($groups === []) {
                return;
            }

            $markup = (new FileLayout('sidebar', JPATH_ADMINISTRATOR . '/components/com_alfa/layouts'))
                ->render(['groups' => $groups]);

            // Prepend our hidden markup to the component output; the script moves it into
            // #sidebarmenu. Mirrors how the application itself sets the component buffer.
            $doc->setBuffer($markup . $doc->getBuffer('component'), 'component');

            $wa = $doc->getWebAssetManager();
            $wa->registerAndUseStyle('com_alfa.sidebar', 'media/com_alfa/css/admin/sidebar.css');
            $wa->registerAndUseScript('com_alfa.sidebar', 'media/com_alfa/js/admin/sidebar.js');
        } catch (Throwable $e) {
            // The menu must never break the page it decorates.
        }
    }
}
