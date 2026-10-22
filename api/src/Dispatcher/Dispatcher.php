<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\Dispatcher;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Service\Maintenance\MaintenanceState;
use Joomla\CMS\Dispatcher\ComponentDispatcher;
use Joomla\CMS\Document\JsonapiDocument;
use Joomla\CMS\Language\LanguageHelper;
use Joomla\CMS\Language\Text;

/**
 * API dispatcher for com_alfa.
 *
 * Mirrors the core ApiDispatcher (which is final, so not extendable) and adds
 * one thing: an optional ?lang= request parameter that selects the CONTENT
 * language for this request, so the list models' COALESCE joins and the Api
 * models' getTranslatedValue() calls answer in the requested language.
 *
 * It deliberately does NOT swap the application's Language object. That object
 * is the UI language; it backs every Text:: string, and replacing it mid
 * request leaves a Language into which no extension .ini was ever loaded, so
 * error prose degrades to raw keys exactly when a client asks for a language.
 * Content language and UI language are one axis on the site, two in an API: a
 * back-office client legitimately wants English messages while editing Greek
 * products.
 *
 * @since  2.0.0
 */
final class Dispatcher extends ComponentDispatcher
{
    /**
     * Load the component's language: the storefront file, then the administrator's over it
     *
     * @return void
     *
     * @since  2.0.0
     */
    protected function loadLanguage()
    {
        // The storefront file first. A price breakdown words its steps with
        // storefront keys (the base step, the discount of a sale, a custom
        // rate), and the catalogue answers that breakdown; without this file
        // a client reads the bare key. The administrator file is loaded after
        // it, so every key both files define keeps its administrator wording.
        $this->app->getLanguage()->load(extension: $this->option, basePath: JPATH_SITE);

        // Load common and local language files.
        $this->app->getLanguage()->load($this->option, JPATH_BASE) ||
        $this->app->getLanguage()->load($this->option, JPATH_ADMINISTRATOR) ||
        $this->app->getLanguage()->load($this->option, JPATH_ADMINISTRATOR . '/components/' . $this->option);
    }

    /**
     * Dispatch a controller task. API Overrides to ensure there is no redirects.
     *
     * @return void
     *
     * @since  2.0.0
     */
    public function dispatch()
    {
        // Maintenance (Clear everything): every request is answered 503 before anything reads an
        // Alfa table. Said in the response itself: the API's error renderer answers an exception it
        // does not know as a 500 without its words.
        if (MaintenanceState::active()) {
            $this->app->setHeader('status', 503, true);
            $document = $this->app->getDocument();

            if ($document instanceof JsonapiDocument) {
                $document->setErrors([['code' => 503, 'title' => Text::_('COM_ALFA_MAINTENANCE_API')]]);
            }

            return;
        }

        $this->applyRequestLanguage();

        $task = $this->input->getCmd('task', 'display');

        // Build controller config data
        $config = ['option' => $this->option];

        // Set name of controller if it is passed in the request
        if ($this->input->exists('controller')) {
            $config['name'] = strtolower($this->input->get('controller'));
        }

        $controller = $this->input->get('controller');
        $controller = $this->getController($controller, ucfirst($this->app->getName()), $config);

        $controller->execute($task);
    }

    /**
     * Resolve ?lang= to a published content language and pin it for this request.
     *
     * Accepts lang_code ("el-GR") or snake ("el_gr"), case-insensitively. An
     * unknown or unpublished tag is ignored rather than rejected, matching how
     * core treats an invalid list[ordering]; Content-Language then tells the
     * client which representation it actually received.
     *
     *
     * @since  2.0.0
     */
    private function applyRequestLanguage(): void
    {
        $requested = $this->input->getString('lang', '');
        $resolved = $this->resolveContentLanguage($requested);

        if ($requested !== '' && $resolved !== null) {
            MultilingualHelper::setContentLanguage($resolved);
        }

        // Always advertise the language the body is actually in, so a cache,
        // a client or a log can tell two representations apart, including
        // when an unknown tag silently fell back to the default.
        $this->app->setHeader(
            'Content-Language',
            $resolved ?? $this->app->getLanguage()->getTag(),
            true,
        );
    }

    /**
     * Match a requested tag against the published content languages.
     *
     * @param string $requested Raw ?lang= value.
     *
     * @return string|null Canonical lang_code, or null when unmatched.
     *
     * @since  2.0.0
     */
    private function resolveContentLanguage(string $requested): ?string
    {
        if ($requested === '') {
            return null;
        }

        $normalised = strtolower(str_replace('_', '-', $requested));

        foreach (LanguageHelper::getContentLanguages() as $language) {
            if (strtolower($language->lang_code) === $normalised) {
                return $language->lang_code;
            }
        }

        return null;
    }
}
