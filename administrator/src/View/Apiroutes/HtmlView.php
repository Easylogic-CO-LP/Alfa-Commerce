<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View\Apiroutes;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\ErrorText;
use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use Alfa\Component\Alfa\Administrator\Helper\RequestScalar;
use Alfa\Component\Alfa\Administrator\Helper\RequestValueRefused;
use Alfa\Component\Alfa\Administrator\Service\Api\ApiAddress;
use Alfa\Component\Alfa\Administrator\Service\Api\ApiAddressListing;
use Alfa\Component\Alfa\Administrator\Service\Api\ApiRouteRegistrar;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormFactoryInterface;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Alfa\Component\Alfa\Administrator\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Throwable;

/**
 * Tools > Security > API routes: every Alfa Commerce endpoint and who may call it, with filters, on a page of its own.
 * The Security tab keeps the counts only, so the report and its filters stay readable beside an integrity verdict
 * that can list a thousand files, and a filter reloads this page alone. Read only.
 *
 * @since  2.3.0
 */
class HtmlView extends BaseHtmlView
{
    /**
     * Where the user's filters are kept between visits, as a Joomla list keeps its own.
     *
     * @since  2.3.0
     */
    private const FILTER_STATE = 'com_alfa.apiroutes.filter';

    /**
     * Every Alfa Commerce endpoint and who may call it ({@see ApiAddressListing::read()}): the endpoints the filters
     * keep in their sections (Alfa Commerce's, each extension's), the summary's counts, who holds each permission, the
     * permissions' titles, the sources, the token's sign-in groups, and whether the token's sign-in and the webservices
     * plugin are enabled. Drawn from a fixed-path partial, as the integrity verdict is.
     *
     * @var array{shown: list<ApiAddress>, filtered: bool, summary: array{addresses: int, plugins: int, accounts: int, superUsers: int}, sections: array{core: array{all: int, shown: list<ApiAddress>}, extensions: list<array{source: string, name: string, all: int, shown: list<ApiAddress>, refused: list<\Alfa\Component\Alfa\Administrator\Service\Api\ApiRouteRefusal>, unavailable: string}>}, holders: list<array{permissions: list<string>, endpoints: int, groups: list<array<string, mixed>>, accounts: list<array<string, mixed>>}>, titles: array<string, string>, sources: array<string, string>, tokenGroups: array{every: bool, titles: list<string>}, tokenPlugin: bool, webservices: bool, unreadable: bool}
     * @since  2.3.0
     */
    public array $apiRoutes = [
        'shown' => [], 'filtered' => false, 'summary' => ['addresses' => 0, 'plugins' => 0, 'accounts' => 0, 'superUsers' => 0],
        'sections' => ['core' => ['all' => 0, 'shown' => []], 'extensions' => []], 'holders' => [], 'titles' => [], 'sources' => [],
        'tokenGroups' => ['every' => false, 'titles' => []], 'tokenPlugin' => false, 'webservices' => false, 'unreadable' => false,
    ];

    /**
     * The filters of the endpoints (forms/filter_apiroutes.xml), drawn by Joomla's search tools.
     *
     * @since  2.3.0
     */
    public ?Form $filterForm = null;

    /**
     * The filters a request sets: name => value.
     *
     * @var array<string, string>
     *
     * @since  2.3.0
     */
    public array $activeFilters = [];

    /**
     * The report of the endpoints, pre-rendered in {@see self::display()} from a fixed-path partial (NOT the
     * overridable layout system) so a template override cannot hide an endpoint, a permission or an account that
     * holds a token; the template only echoes this string.
     *
     * @since  2.3.0
     */
    public string $apiRoutesHtml = '';

    /**
     * Render the page.
     *
     * @param string|null $tpl The template name.
     *
     * @since  2.3.0
     */
    public function display($tpl = null): void
    {
        // Gated as Tools is; menu hiding is cosmetic, this is the real boundary.
        if (!Factory::getApplication()->getIdentity()->authorise('alfa.tools', 'com_alfa')) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        // Every Alfa Commerce endpoint and who may call it, from a fixed-path partial: what an override draws cannot
        // hide an endpoint or a token holder.
        $this->apiRoutes = $this->apiRoutes();
        ob_start();
        include __DIR__ . '/_apiroutes.php';
        $this->apiRoutesHtml = ob_get_clean();

        $this->addToolbar();

        parent::display($tpl);
    }

    /**
     * Every Alfa Commerce endpoint and who may call it, and the ones the filters keep (filter[source],
     * filter[permission], filter[search]), remembered for the user as Joomla's lists remember theirs; the filter form
     * with the sources and the permissions the endpoints have. A reading that fails is said on the page and logged.
     *
     * @return array{shown: list<ApiAddress>, filtered: bool, summary: array{addresses: int, plugins: int, accounts: int, superUsers: int}, sections: array{core: array{all: int, shown: list<ApiAddress>}, extensions: list<array{source: string, name: string, all: int, shown: list<ApiAddress>, refused: list<\Alfa\Component\Alfa\Administrator\Service\Api\ApiRouteRefusal>, unavailable: string}>}, holders: list<array{permissions: list<string>, endpoints: int, groups: list<array<string, mixed>>, accounts: list<array<string, mixed>>}>, titles: array<string, string>, sources: array<string, string>, tokenGroups: array{every: bool, titles: list<string>}, tokenPlugin: bool, webservices: bool, unreadable: bool}
     *
     * @since  2.3.0
     */
    private function apiRoutes(): array
    {
        $empty = $this->apiRoutes;

        try {
            $listing = ApiAddressListing::read();
        } catch (Throwable $e) {
            Log::add('API routes: the REST API addresses could not be read: ' . $e::class . ': ' . ErrorText::of($e), Log::ERROR, ApiRouteRegistrar::LOG);

            return ['unreadable' => true] + $empty;
        }

        // The user's last filters, as a Joomla list keeps them: the request's when it sends them, else the stored ones.
        $app = Factory::getApplication();
        $filters = (array) $app->getUserStateFromRequest(self::FILTER_STATE, 'filter', [], 'array');

        try {
            $chosen = [
                'search' => trim((string) RequestScalar::member($filters, 'search', 'STRING', 'filter')),
                'source' => (string) RequestScalar::member($filters, 'source', 'CMD', 'filter'),
                'permission' => (string) RequestScalar::member($filters, 'permission', 'CMD', 'filter'),
            ];
        } catch (RequestValueRefused $e) {
            // A refused value is never kept: the next visit opens with no filter.
            $app->setUserState(self::FILTER_STATE, []);

            throw $e;
        }

        $sources = ApiAddressListing::sources(addresses: $listing['addresses']);
        $this->activeFilters = array_filter($chosen, static fn (string $value): bool => $value !== '');
        $this->filterForm = $this->filterForm(sources: $sources, titles: $listing['titles'], chosen: $chosen);
        $shown = ApiAddressListing::filter(addresses: $listing['addresses'], source: $chosen['source'], permission: $chosen['permission'], search: $chosen['search']);

        return [
            'shown' => $shown,
            'filtered' => $this->activeFilters !== [],
            'summary' => ApiAddressListing::summary(addresses: $listing['addresses']),
            'sections' => ApiAddressListing::sections(addresses: $listing['addresses'], shown: $shown, refused: $listing['refused'], unavailable: $listing['unavailable']),
            'holders' => ApiAddressListing::holders(addresses: $shown),
            'titles' => $listing['titles'],
            'sources' => $sources,
            'tokenGroups' => $listing['tokenGroups'],
            'tokenPlugin' => $listing['tokenPlugin'],
            'webservices' => $listing['webservices'],
            'unreadable' => false,
        ];
    }

    /**
     * The filter form: forms/filter_apiroutes.xml with an option for each source and each permission the endpoints
     * have (a permission named with its title), bound to the request's filters.
     *
     * @param array<string, string> $sources Source => name.
     * @param array<string, string> $titles Permission => title.
     * @param array{search: string, source: string, permission: string} $chosen The request's filters.
     *
     * @since  2.3.0
     */
    private function filterForm(array $sources, array $titles, array $chosen): ?Form
    {
        try {
            $form = Factory::getContainer()->get(FormFactoryInterface::class)->createForm('com_alfa.apiroutes.filter', ['control' => '']);
            $form->loadFile(JPATH_ADMINISTRATOR . '/components/com_alfa/forms/filter_apiroutes.xml');
        } catch (Throwable $e) {
            Log::add('API routes: the filters could not be built: ' . $e::class . ': ' . ErrorText::of($e), Log::ERROR, ApiRouteRegistrar::LOG);

            return null;
        }

        $option = static fn (string $text): string => htmlspecialchars($text, ENT_XML1 | ENT_NOQUOTES, 'UTF-8');
        $sourceField = $form->getField('source', 'filter');
        $permissionField = $form->getField('permission', 'filter');

        if ($sourceField instanceof ListField) {
            foreach ($sources as $value => $name) {
                $sourceField->addOption($option($name), ['value' => $value]);
            }
        }

        if ($permissionField instanceof ListField) {
            $labels = [];

            foreach ($titles as $name => $title) {
                $labels[$name] = $title !== '' ? $title . ' (' . $name . ')' : $name;
            }

            natcasesort($labels);

            foreach ($labels as $name => $label) {
                $permissionField->addOption($option($label), ['value' => (string) $name]);
            }
        }

        $form->bind(['filter' => $chosen]);

        return $form;
    }

    /**
     * The toolbar: back to the Security tab of Tools, then the Webhooks, whose consumers call these endpoints.
     *
     * @since  2.3.0
     */
    protected function addToolbar(): void
    {
        ToolbarHelper::title(Text::_('COM_ALFA_TITLE_APIROUTES'), 'generic');

        $toolbar = $this->getDocument()->getToolbar();
        $toolbar->link(Text::_('COM_ALFA_TOOLS_BACK'), 'index.php?option=com_alfa&view=tools#integrity')
            ->icon('icon-arrow-left');
        $toolbar->link(Text::_('COM_ALFA_WEBHOOKS_TITLE'), 'index.php?option=com_alfa&view=webhooks')
            ->icon('icon-share-alt');

        NotificationHelper::toolbarBadge($toolbar);
    }
}
