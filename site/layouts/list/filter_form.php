<?php
/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Layout: filter_form
 *
 * Renders the list filter/search form. The submit behaviour that keeps empty controls
 * out of the URL lives in the `com_alfa.list` script, loaded below; it has to survive the
 * filters module replacing this markup, which an inline script could not.
 *
 * Expected $displayData keys:
 *   - view   : the current HtmlView instance (must expose $filterForm)
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Search\SearchInputLayoutEvent;
use Alfa\Component\Alfa\Administrator\Plugin\AlfaPluginHelper;
use Alfa\Component\Alfa\Administrator\Plugin\PluginUnavailable;
use Alfa\Component\Alfa\Administrator\Service\Search\SearchEngineRegistry;
use Alfa\Component\Alfa\Site\Helper\Layout;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

$view       = $displayData['view'];
$filterForm = $view->filterForm ?? null;

if (empty($filterForm)) {
	return;
}

$wa = $view->document->getWebAssetManager();

// The form's own submit behaviour: empty controls never reach the URL, a changed keyword
// starts a new search. It loads here, beside the form, for the same reason the search widget
// does: every view that renders this layout renders this form. It is an ASSET rather than an
// inline script because the filters module re-renders the listing by replacing #alfa-app, and
// a script that ran once on DOMContentLoaded lost the form on the first dynamic apply.
$wa->useScript('com_alfa.list');

// SEARCH ENHANCEMENT FOLLOWS THE DECLARATION, NOT THE VIEW. The assets load here (beside
// the markup that needs them) rather than in each view template, because every view that
// renders this layout renders that input: items and manufacturers today, anything later.
// A template-side load means each new view has to remember, and forgetting produces a
// declared input that silently never gets enhanced.
//
// Keyed on the FIELD's own `data-alfa-search`, so the form XML stays the single source of
// truth: filter_items.xml declares it and gets the widget, filter_manufacturers.xml does
// not and keeps a plain input with no asset weight. Declaring it there later is a one-line
// XML change that needs no PHP.
$searchField = $filterForm->getField('search', 'filter');

if ($searchField && $searchField->getAttribute('data-alfa-search')) {
	$wa->useStyle('com_alfa.search')
		->useScript('com_alfa.search');

	// The endpoint is passed as a script option rather than assembled in JS, because only
	// PHP knows the site's SEF and language routing; a URL guessed client-side would
	// search the wrong language the moment a visitor is on a non-default one.
	$view->document->addScriptOptions('com_alfa.search', [
		'url' => Route::_('index.php?option=com_alfa&task=search.search'),
		'minChars' => 2,
	]);

	// No Text::script() registrations: the widget carries NO language keys at all.
	// Every translated string lives in the enhance_templates layout below, already
	// rendered; the script only clones what the layout authored.
}
?>

    <form action="<?php echo htmlspecialchars(Uri::getInstance()->toString()); ?>"
          method="get"
          name="adminForm"
          id="adminForm">

		<?php
		// THE CONFIGURED ENGINE MAY TAKE OVER THE INPUT: an engine's input rendering ships with
		// the engine, never with the component. This renders the input only; an engine's
		// consumer of the result set's attribution loads when the items view announces the
		// result set (onAlfaResultSetRender). The question goes to the configured engine's
		// plugin ALONE (2.2.0, mode B): no other listener can take the input. With no such
		// plugin (the database search, an engine core ships itself, a plugin disabled or
		// failing) this renders the stock searchtools input exactly as before. `method`
		// carries the configured engine key; redirects are deliberately not honoured here.
		$inputEvent = new SearchInputLayoutEvent('onAlfaSearchInputLayout', [
			'subject' => $view,
			'method'  => (string) ComponentHelper::getParams('com_alfa')->get('search_engine', 'database'),
			// This layout serves several list views (items AND manufacturers today), so the
			// surface travels with the question: the engine may dress them differently, or
			// claim only one.
			'context' => 'com_alfa.' . $view->getName(),
		]);
		// The request's registry: the engines are discovered once per page, whoever asks (2.2.0).
		$enginePlugin = SearchEngineRegistry::shared()->configuredPlugin();

		if ($enginePlugin !== null) {
			$plugins = AlfaPluginHelper::get();

			try {
				$engine = $plugins->bootSingle($enginePlugin);
			} catch (PluginUnavailable $unavailable) {
				$engine = null;
				Log::add('Search input: ' . $unavailable->getMessage() . ' The stock input renders.', Log::DEBUG, 'com_alfa.search');
			}

			// Anything leaving the engine's own handler is its failure: the stock input renders.
			if ($engine !== null && $plugins->declares($engine, 'onAlfaSearchInputLayout')) {
				try {
					$plugins->dispatchTo($engine, $inputEvent);
				} catch (Throwable $e) {
					Log::add('Search input: ' . $enginePlugin . ' failed (' . $e::class . ': ' . $e->getMessage() . '); the stock input renders.', Log::WARNING, 'com_alfa.search');
					$inputEvent = new SearchInputLayoutEvent('onAlfaSearchInputLayout', ['subject' => $view, 'method' => '', 'context' => 'com_alfa.' . $view->getName()]);
				}
			}
		}

		if ($inputEvent->getLayout() !== '') {
			echo Layout::render(
				$inputEvent->getLayout(),
				array_merge(
					['view' => $view, 'context' => $inputEvent->getContext()],
					$inputEvent->getLayoutData(),
				),
				$inputEvent->getLayoutPluginType(),
				$inputEvent->getLayoutPluginName(),
			);
		} else {
			// The storefront bar, through the ONE entry point an engine's layout uses
			// too; resolution and the merchant's Filter Options setting live there.
			echo Layout::searchtools($view);
		}

		// THE WIDGET'S ENTIRE MARKUP, as inert <template> fragments the script clones:
		// enhance.js creates no elements and sets no classes of its own, so overriding
		// `search/enhance_templates.php` restyles the whole dropdown. Rendered only when
		// the declared input exists: without it neither the script nor its markup ships.
		if ($searchField && $searchField->getAttribute('data-alfa-search')) {
			echo Layout::render('search.enhance_templates');
		}
		?>

    </form>
