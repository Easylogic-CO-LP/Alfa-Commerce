<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Panel\PanelDispatcher;
use Alfa\Component\Alfa\Administrator\Plugin\AlfaPluginHelper;
use Joomla\CMS\Document\Document;
use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\Database\DatabaseInterface;
use Throwable;

/**
 * The one place a screen's guided tour meets its toolbar: every com_alfa view calls
 * {@see self::toolbarHelp()} right after its Options button (the slot Joomla's own
 * `$toolbar->help()` uses) and the screens that HAVE a tour get a Help button that
 * starts it (the core `button-start-guidedtour` contract: plg_system_guidedtours
 * does the launching). Screens without a mapped tour, or sites where the tour
 * rows are missing, get nothing: the button never promises what it cannot start.
 *
 * @since  2.0.0
 */
final class TourHelper
{
    /**
     * Which tour teaches which screen. Grows as tours ship; a view absent here
     * simply has no Help button yet.
     *
     * @var array<string, string>
     * @since  2.0.0
     */
    public const VIEW_TOURS = [
        'dashboard' => 'com_alfa.welcome',
        'categories' => 'com_alfa.categories',
        'items' => 'com_alfa.product',
        'payments' => 'com_alfa.payments',
        'shipments' => 'com_alfa.shipping',
        'formfields' => 'com_alfa.form-fields',
        'formfieldgroups' => 'com_alfa.form-field-groups',
        'manufacturers' => 'com_alfa.manufacturers',
        'taxes' => 'com_alfa.taxes',
        'discounts' => 'com_alfa.discounts',
        'cartrules' => 'com_alfa.cart-rules',
        'orderstatuses' => 'com_alfa.order-statuses',
        'locations' => 'com_alfa.stock-locations',
        'currencies' => 'com_alfa.currencies',
        'itemfields' => 'com_alfa.item-fields',
        'documenttypes' => 'com_alfa.document-types',
        'documentseries' => 'com_alfa.document-series',
        'documents' => 'com_alfa.sales-documents',
        'orders' => 'com_alfa.orders',
        'users' => 'com_alfa.customers',
        'usergroups' => 'com_alfa.customer-groups',
        'inventorymovements' => 'com_alfa.stock-movements',
        'reservations' => 'com_alfa.stock-reservations',
        'places' => 'com_alfa.places',
        'notifications' => 'com_alfa.notifications',
        'tools' => 'com_alfa.tools',
    ];

    /**
     * Append the screen's tour as a toolbar Help button. Call it right AFTER the
     * Options button, where every Joomla screen keeps its Help.
     *
     * @param Toolbar $toolbar The view's Toolbar instance.
     * @param string $view The view name (usually `$this->getName()`).
     *
     *
     * @since  2.0.0
     */
    public static function toolbarHelp(Toolbar $toolbar, string $view): void
    {
        $uid = self::VIEW_TOURS[strtolower($view)] ?? '';

        if ($uid === '') {
            return;
        }

        // No tour row in the DB (not seeded, unpublished) => no button.
        if (SetupHelper::tourAvailability([$uid]) === []) {
            return;
        }

        // Joomla's own HelpButton recipe (btn-info + icon-question) minus its popup:
        // the core `button-start-guidedtour` contract starts our tour instead.
        $toolbar->basicButton('alfa-tour-help', 'COM_ALFA_TOOLBAR_TOUR_HELP')
            ->icon('icon-question')
            ->buttonClass('btn btn-info alfa-tour-help button-start-guidedtour')
            ->attributes(['data-gt-uid' => $uid]);
    }

    /**
     * Ship the tour-grammar supplement (Next on press steps, "Finish without saving")
     * with an admin HTML page: the script, its label and the ids of OUR published
     * tours; the script leaves every other tour exactly as the engine renders it.
     * A tour runs across pages, so the Dispatcher calls this on every admin page.
     *
     * @param Document $document The page document; non-HTML documents get nothing.
     *
     *
     * @since  2.0.0
     */
    public static function attachGrammar(Document $document): void
    {
        if (!$document instanceof HtmlDocument) {
            return;
        }

        self::loadContributorLanguages();

        $app = Factory::getApplication();

        // These assets travel to screens that are not ours (a menu tour lives in
        // com_menus, and inside the type window's iframe): without the component's
        // registry file the names do not resolve there, and without its language the
        // buttons render raw keys. Both calls are idempotent.
        $app->getLanguage()->load('com_alfa', JPATH_ADMINISTRATOR);

        $wa = $document->getWebAssetManager();
        $wa->getRegistry()->addExtensionRegistryFile('com_alfa');

        $wa->useStyle('com_alfa.tours')
            ->useScript('com_alfa.tours');
        $document->addScriptOptions('com_alfa.tours', ['ids' => self::ownTourIds()]);
        Text::script('COM_ALFA_TOUR_FINISH');
        Text::script('COM_ALFA_TOUR_SKIP');
        Text::script('COM_ALFA_TOUR_CLOSE');
    }

    /**
     * Load the language of each platform plugin that has a published tour of its own, for the sake of that tour.
     *
     * A plugin's tour carries language KEYS into the database, and Joomla looks for
     * their texts in a file per tour, so the Take a Tour list and the running tour
     * would both render raw PLG_... strings. Importing or even booting the plugin is
     * NOT enough: measured on this install, only an explicit load() resolves them.
     *
     * ONLY THE PLUGINS WITH A TOUR (2.2.1). A plugin's tour is named after its own
     * extension, plg_<group>_<element>.<tour> (Joomla's own mapping from a tour to
     * its wording); the Take a Tour list shows every published tour and the running
     * tour is one of them. Loading every platform plugin's language on every page
     * cost each page a file per plugin and showed the strings of a plugin that
     * forgets to load its own language, while Guided Tours is on, hiding its fault.
     *
     *
     * @since  2.0.0
     */
    private static function loadContributorLanguages(): void
    {
        $group = PanelDispatcher::PLATFORM_GROUP;

        // The group's enabled plugins as Joomla lists them, through the plugin helper (2.2.0): their strings are
        // loaded, nothing is imported or booted.
        try {
            $plugins = AlfaPluginHelper::get()->available($group);
        } catch (Throwable $e) {
            return;                                 // a broken contributor costs the page nothing
        }

        $withTour = [];

        foreach (self::ownTours() as $uid) {
            if (preg_match('/^plg_' . preg_quote($group, '/') . '_([a-z0-9\-]+)\./', strtolower($uid), $match) === 1) {
                $withTour[$match[1]] = true;
            }
        }

        foreach ($plugins as $plugin) {
            if (isset($withTour[$plugin->element])) {
                AlfaHelper::loadPluginLanguage(group: $group, element: $plugin->element);
            }
        }
    }

    /**
     * Ids of the published `com_alfa.*` tours: what the tour engine keeps in
     * session storage while a tour runs. One read per request.
     *
     * @return int[]
     *
     * @since  2.0.0
     */
    public static function ownTourIds(): array
    {
        return array_keys(self::ownTours());
    }

    /**
     * The published tours Alfa Commerce seeded, core's and its platform plugins', by id: their uids. One read per
     * request.
     *
     * @return array<int, string>
     *
     * @since  2.2.1
     */
    private static function ownTours(): array
    {
        static $tours = null;

        if ($tours === null) {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->getQuery(true)
                ->select($db->quoteName(['id', 'uid']))
                ->from($db->quoteName('#__guidedtours'))
                // OURS IS WHAT THE SEEDER STAMPED, not what the uid starts with: a
                // platform plugin names its tour after its own extension (Joomla reads
                // the wording from that prefix), and such a tour must still get our
                // grammar: Close tutorial, Next on a press step, Skip when a target
                // is missing. Matching on the uid quietly excluded every contributor.
                ->where($db->quoteName('note') . ' LIKE ' . $db->quote('alfa:v%'))
                ->where($db->quoteName('published') . ' = 1');

            $tours = [];

            foreach ((array) $db->setQuery($query)->loadObjectList() as $row) {
                $tours[(int) $row->id] = (string) $row->uid;
            }
        }

        return $tours;
    }
}
