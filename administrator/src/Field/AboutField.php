<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\LanguageHelper;
use Joomla\CMS\Language\Multilanguage;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Version;
use Joomla\Database\DatabaseInterface;

/**
 * The About card of the Options screen: who made Alfa Commerce, which release
 * is installed, what it runs on, whether the runtime meets its requirements,
 * how Joomla is configured and which Alfa extensions are present: a
 * read-only page the shop can hand to support as-is.
 *
 * Every string is a literal, on purpose. The card is the product's own
 * statement of origin (company, author, licence), and that is not
 * translatable text, so nothing here goes through the language layer and no
 * language override can reword it. Only the release (installed manifest,
 * extension registry, update tables) and the environment (runtime, Joomla
 * configuration) are read live, because those are the facts that differ from
 * shop to shop.
 *
 * Markup is the administrator template's own vocabulary (cards, tables,
 * badges); the field ships no stylesheet.
 *
 * The field stores nothing: it renders no input, so the form never receives
 * a value for it.
 *
 * @since  1.10.0
 */
class AboutField extends FormField
{
    /**
     * @var string Field type.
     */
    protected $type = 'About';

    /**
     * @var bool The card carries its own heading; the form label stays empty.
     */
    protected $hiddenLabel = true;

    /**
     * @var bool The card is the description.
     */
    protected $hiddenDescription = true;

    private const PRODUCT = 'Alfa Commerce';
    private const TAGLINE = 'The smartest, fastest and easiest e-commerce solution for companies, developed with love by Easylogic.';
    private const COMPANY = 'Easylogic CO LP';
    private const COMPANY_URL = 'https://easylogic.gr';
    private const AUTHOR = 'Agamemnon Fakas';
    private const EMAIL = 'info@alfacommerce.gr';
    private const PRODUCT_URL = 'https://alfacommerce.gr';
    private const MANUAL_URL = 'https://manual.alfacommerce.gr';
    private const REPOSITORY_URL = 'https://github.com/Easylogic-CO-LP/Alfa-Commerce';
    private const ISSUES_URL = 'https://github.com/Easylogic-CO-LP/Alfa-Commerce/issues';
    private const LICENSE = 'GNU General Public License, version 3 or later';
    private const LICENSE_URL = 'https://www.gnu.org/licenses/gpl-3.0.html';
    private const FIRST_YEAR = 2024;
    private const MARK = 'media/com_alfa/images/admin/menu/alfa.svg';

    /**
     * The card renders its own heading, so the form label is empty.
     *
     * @return string
     * @since  1.10.0
     */
    protected function getLabel()
    {
        return '';
    }

    /**
     * Render the card.
     *
     * @return string
     * @since  1.10.0
     */
    protected function getInput()
    {
        $manifest = $this->manifest();
        $extensions = $this->installedExtensions();
        $installed = $manifest['version'];
        $registered = $this->registeredVersion($extensions);
        $update = $this->availableUpdate($installed);
        $lastCheck = $this->lastUpdateCheck();
        $env = $this->environment();
        $requirements = $this->requirements();
        $joomla = $this->joomlaState();
        $updateUrl = Route::_('index.php?option=com_installer&view=update', false);
        $copyright = '© ' . self::FIRST_YEAR . '-' . date('Y') . ' ' . self::COMPANY . ' / ' . self::AUTHOR . '. All rights reserved.';

        $html = '<div class="alfa-about">';

        // Identity
        $html .= '<div class="card mb-3"><div class="card-body d-flex align-items-start gap-3">'
            . '<span class="d-inline-flex align-items-center justify-content-center bg-dark rounded-3 p-3 flex-shrink-0">'
            . '<img src="' . self::e(Uri::root(true) . '/' . self::MARK) . '" width="40" height="33" alt="">'
            . '</span>'
            . '<div>'
            . '<h3 class="h4 mb-1">' . self::e(self::PRODUCT)
            . ($installed !== '' ? ' <span class="badge bg-secondary font-monospace fw-normal align-middle">' . self::e($installed) . '</span>' : '')
            . '</h3>'
            . '<p class="text-muted mb-1">' . self::e(self::TAGLINE) . '</p>'
            . '<p class="mb-0">Developed by ' . self::link(self::COMPANY_URL, self::COMPANY)
            . ' <span class="text-muted">-</span> ' . self::link('mailto:' . self::EMAIL, self::EMAIL, false)
            . '</p>'
            . '</div>'
            . '</div></div>';

        $html .= '<div class="row g-3">';

        // Release
        $facts = self::fact('Installed version', $installed !== '' ? self::e($installed) : 'unknown');

        if ($registered !== '' && $registered !== $installed) {
            $facts .= self::fact(
                'Registered version',
                self::e($registered) . ' <span class="text-muted">(the extension registry lags the files on disk; reinstalling the package refreshes it)</span>',
            );
        }

        $checked = $lastCheck !== '' ? 'last checked ' . self::e($lastCheck) : 'not checked yet';
        $facts .= self::fact(
            'Update',
            $update !== ''
                ? '<span class="badge bg-warning text-dark">' . self::e($update) . ' available</span> '
                    . self::link($updateUrl, 'Install from Extensions > Update', false)
                : 'No newer release recorded <span class="text-muted">(' . $checked . ')</span> - '
                    . self::link($updateUrl, 'check for updates', false),
        );

        if ($manifest['server'] !== '') {
            $facts .= self::fact('Update server', '<code>' . self::e($manifest['server']) . '</code>');
        }

        $facts .= self::fact('License', self::link(self::LICENSE_URL, self::LICENSE));
        $facts .= self::fact('Copyright', self::e($copyright));
        $html .= '<div class="col-lg-6">' . self::panel('Release', '<dl class="row mb-0">' . $facts . '</dl>') . '</div>';

        // Environment
        $facts = '';

        foreach ($env as $label => $value) {
            $facts .= self::fact($label, $label === 'Site' ? self::link($value, $value) : self::e($value));
        }

        $html .= '<div class="col-lg-6">' . self::panel('Environment', '<dl class="row mb-0">' . $facts . '</dl>') . '</div>';

        // Requirements
        $rows = '';

        foreach ($requirements as $req) {
            if ($req['loaded']) {
                $status = '<span class="badge bg-success">Loaded</span>';
            } elseif ($req['required']) {
                $status = '<span class="badge bg-danger">Missing (required)</span>';
            } else {
                $status = '<span class="badge bg-warning text-dark">Missing (optional)</span>';
            }

            $rows .= '<tr><td><code>' . self::e($req['name']) . '</code></td><td>' . self::e($req['used']) . '</td><td>' . $status . '</td></tr>';
        }

        $html .= '<div class="col-lg-6">' . self::panel(
            'PHP requirements',
            '<div class="table-responsive"><table class="table table-sm mb-0">'
                . '<thead><tr><th scope="col">Extension</th><th scope="col">Used for</th><th scope="col">Status</th></tr></thead>'
                . '<tbody>' . $rows . '</tbody></table></div>',
            false,
        ) . '</div>';

        // Joomla configuration
        $facts = '';

        foreach ($joomla as $label => $value) {
            $facts .= self::fact($label, self::e($value));
        }

        $html .= '<div class="col-lg-6">' . self::panel('Joomla', '<dl class="row mb-0">' . $facts . '</dl>') . '</div>';

        // Installed Alfa extensions
        $rows = '';

        foreach ($extensions as $row) {
            $rows .= '<tr>'
                . '<td><code>' . self::e($row->id) . '</code></td>'
                . '<td>' . self::e($row->kind) . '</td>'
                . '<td>' . ($row->version !== '' ? self::e($row->version) : '-') . '</td>'
                . '<td>' . ($row->enabled
                    ? '<span class="badge bg-success">Enabled</span>'
                    : '<span class="badge bg-secondary">Disabled</span>') . '</td>'
                . '</tr>';
        }

        if ($extensions === []) {
            $rows = '<tr><td colspan="4">No Alfa extension is registered.</td></tr>';
        }

        $html .= '<div class="col-12">' . self::panel(
            'Installed Alfa extensions <span class="badge bg-secondary fw-normal ms-1">' . \count($extensions) . '</span>',
            '<div class="table-responsive"><table class="table table-sm table-hover mb-0">'
                . '<thead><tr><th scope="col">Extension</th><th scope="col">Type</th><th scope="col">Version</th><th scope="col">State</th></tr></thead>'
                . '<tbody>' . $rows . '</tbody></table></div>',
            false,
        ) . '</div>';

        // Links
        $html .= '<div class="col-12"><div class="d-flex flex-wrap gap-2">'
            . self::button(self::PRODUCT_URL, 'Product site')
            . self::button(self::MANUAL_URL, 'Manual')
            . self::button(self::REPOSITORY_URL, 'Source code')
            . self::button(self::ISSUES_URL, 'Report an issue')
            . '</div></div>';

        // Plain-text report for support requests
        $html .= '<div class="col-12"><details>'
            . '<summary class="text-muted">Support report (plain text; click the box to select it)</summary>'
            . '<pre class="bg-body-tertiary border rounded p-3 mt-2 mb-0 small user-select-all">'
            . self::e($this->report($installed, $registered, $update, $lastCheck, $env, $requirements, $joomla, $extensions))
            . '</pre></details></div>';

        $html .= '</div></div>';

        return $html;
    }

    /**
     * Facts read from the installed manifest: the release on disk and the
     * update server it announces.
     *
     * @return array{version: string, server: string} Empty strings when the manifest cannot be read
     * @since  1.10.0
     */
    private function manifest(): array
    {
        $xml = @simplexml_load_file(JPATH_ADMINISTRATOR . '/components/com_alfa/alfa.xml');

        return [
            'version' => $xml ? trim((string) $xml->version) : '',
            'server' => $xml && isset($xml->updateservers->server) ? trim((string) $xml->updateservers->server) : '',
        ];
    }

    /**
     * Every installed Alfa extension (the component, the plugins in the
     * `alfa*` folders, the `mod_alfa*` modules and the `pkg_alfa*` packages)
     * with the version each registered on install.
     *
     * @return object[] Rows with id, kind, type, version, enabled; component first
     * @since  1.10.0
     */
    private function installedExtensions(): array
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select($db->quoteName(['type', 'element', 'folder', 'client_id', 'enabled', 'manifest_cache']))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('state') . ' = 0')
            ->extendWhere('AND', [
                '(' . $db->quoteName('type') . ' = ' . $db->quote('component')
                    . ' AND ' . $db->quoteName('element') . ' = ' . $db->quote('com_alfa') . ')',
                '(' . $db->quoteName('type') . ' = ' . $db->quote('plugin')
                    . ' AND ' . $db->quoteName('folder') . ' LIKE ' . $db->quote('alfa%') . ')',
                '(' . $db->quoteName('type') . ' = ' . $db->quote('module')
                    . ' AND ' . $db->quoteName('element') . ' LIKE ' . $db->quote($db->escape('mod_alfa', true) . '%') . ')',
                '(' . $db->quoteName('type') . ' = ' . $db->quote('package')
                    . ' AND ' . $db->quoteName('element') . ' LIKE ' . $db->quote($db->escape('pkg_alfa', true) . '%') . ')',
            ], 'OR');

        $rows = $db->setQuery($query)->loadObjectList() ?: [];
        $rank = ['component' => 0, 'package' => 1, 'plugin' => 2, 'module' => 3];

        foreach ($rows as $row) {
            $cache = json_decode((string) $row->manifest_cache, true) ?: [];
            $row->version = trim((string) ($cache['version'] ?? ''));
            $row->enabled = (int) $row->enabled === 1;
            $row->id = $row->type === 'plugin' ? $row->folder . '/' . $row->element : $row->element;
            $row->kind = match ($row->type) {
                'component' => 'Component',
                'package' => 'Package',
                'plugin' => 'Plugin',
                'module' => (int) $row->client_id === 1 ? 'Module (administrator)' : 'Module (site)',
                default => ucfirst($row->type),
            };
        }

        usort($rows, static fn ($a, $b) => [$rank[$a->type] ?? 9, $a->id] <=> [$rank[$b->type] ?? 9, $b->id]);

        return $rows;
    }

    /**
     * The version the extension registry holds for the component: what the
     * update system compares against. Differs from the manifest on disk only
     * when files were copied over without the installer.
     *
     * @param object[] $extensions The rows from {@see installedExtensions()}
     *
     * @return string '' when the component is not registered
     * @since  1.10.0
     */
    private function registeredVersion(array $extensions): string
    {
        foreach ($extensions as $row) {
            if ($row->type === 'component') {
                return $row->version;
            }
        }

        return '';
    }

    /**
     * The newest com_alfa release the update system has fetched, when it is
     * newer than the installed one.
     *
     * @param string $installed The installed version
     *
     * @return string '' when nothing newer is recorded
     * @since  1.10.0
     */
    private function availableUpdate(string $installed): string
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select($db->quoteName('version'))
            ->from($db->quoteName('#__updates'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('com_alfa'));

        $newest = '';

        foreach ($db->setQuery($query)->loadColumn() ?: [] as $version) {
            if ($newest === '' || version_compare($version, $newest, '>')) {
                $newest = $version;
            }
        }

        if ($newest === '' || ($installed !== '' && !version_compare($newest, $installed, '>'))) {
            return '';
        }

        return $newest;
    }

    /**
     * When Joomla last asked the component's update server, in the shop's
     * timezone.
     *
     * @return string 'Y-m-d H:i', '' when the server was never queried
     * @since  1.10.0
     */
    private function lastUpdateCheck(): string
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select('MAX(' . $db->quoteName('s.last_check_timestamp') . ')')
            ->from($db->quoteName('#__update_sites', 's'))
            ->join('INNER', $db->quoteName('#__update_sites_extensions', 'se'), $db->quoteName('se.update_site_id') . ' = ' . $db->quoteName('s.update_site_id'))
            ->join('INNER', $db->quoteName('#__extensions', 'e'), $db->quoteName('e.extension_id') . ' = ' . $db->quoteName('se.extension_id'))
            ->where($db->quoteName('e.type') . ' = ' . $db->quote('component'))
            ->where($db->quoteName('e.element') . ' = ' . $db->quote('com_alfa'));

        $stamp = (int) $db->setQuery($query)->loadResult();

        if ($stamp <= 0) {
            return '';
        }

        return Factory::getDate('@' . $stamp)->setTimezone(AlfaHelper::shopTimezone())->format('Y-m-d H:i');
    }

    /**
     * The runtime the shop is on: the first thing a support request needs.
     *
     * @return array<string, string> Label => value, in display order
     * @since  1.10.0
     */
    private function environment(): array
    {
        $app = Factory::getApplication();
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $engine = (string) $db->getServerType();
        $engine = match (true) {
            method_exists($db, 'isMariaDb') && $db->isMariaDb() => 'MariaDB',
            $engine === 'mysql' => 'MySQL',
            $engine === 'postgresql' => 'PostgreSQL',
            default => ucfirst($engine),
        };

        $execution = (int) ini_get('max_execution_time');

        return [
            'Joomla' => (new Version())->getShortVersion(),
            'PHP' => PHP_VERSION . ' (' . PHP_SAPI . ')',
            'Database' => $engine . ' ' . $db->getVersion(),
            'Web server' => $app->getInput()->server->getString('SERVER_SOFTWARE', '') ?: 'unknown',
            'Memory limit' => (string) ini_get('memory_limit'),
            'Upload limit' => ini_get('upload_max_filesize') . ' (post ' . ini_get('post_max_size') . ')',
            'Execution time' => $execution > 0 ? $execution . ' s' : 'unlimited',
            'Timezone' => AlfaHelper::shopTimezone()->getName(),
            'Site' => Uri::root(),
        ];
    }

    /**
     * The PHP extensions Alfa relies on, what each is used for, and whether
     * the shop can run without it.
     *
     * @return array<int, array{name: string, used: string, required: bool, loaded: bool}>
     * @since  1.10.0
     */
    private function requirements(): array
    {
        $needs = [
            ['bcmath',   'Exact decimal arithmetic of stock quantities',                      true],
            ['intl',     'Text normalisation, currency and date formatting',                  true],
            ['mbstring', 'Multibyte text - search, aliases, names',                           true],
            ['gd',       'Image thumbnails and upload validation',                            true],
            ['openssl',  'HTTPS calls to payment gateways and the update server',             true],
            ['curl',     'Gateway and webhook HTTP calls (PHP streams are the fallback)',     false],
            ['zip',      'Package export',                                                    false],
            ['fileinfo', 'MIME detection for uploads and downloads',                          false],
        ];

        return array_map(
            static fn (array $need) => [
                'name' => $need[0],
                'used' => $need[1],
                'required' => $need[2],
                'loaded' => \extension_loaded($need[0]),
            ],
            $needs,
        );
    }

    /**
     * The Joomla settings that decide how a support ticket is read: debugging,
     * caching, routing, templates, languages and the table prefix.
     *
     * @return array<string, string> Label => value, in display order
     * @since  1.10.0
     */
    private function joomlaState(): array
    {
        $app = Factory::getApplication();
        $caching = (int) $app->get('caching', 0);
        $default = (string) ComponentHelper::getParams('com_languages')->get('site', 'en-GB');
        $codes = [];

        foreach (LanguageHelper::getContentLanguages([1]) as $language) {
            $codes[] = $language->lang_code . ($language->lang_code === $default ? ' (default)' : '');
        }

        return [
            'Debug' => (int) $app->get('debug', 0) ? 'On' : 'Off',
            'Error reporting' => (string) $app->get('error_reporting', 'default'),
            'System cache' => $caching === 0
                ? 'Off'
                : ($caching === 1 ? 'Conservative' : 'Progressive') . ' (' . $app->get('cache_handler', 'file') . ')',
            'SEF URLs' => ((int) $app->get('sef', 1) ? 'On' : 'Off')
                . ((int) $app->get('sef_rewrite', 0) ? ', URL rewriting on' : ', URL rewriting off'),
            'Site template' => $this->homeTemplate(0),
            'Admin template' => $this->homeTemplate(1),
            'Languages' => ($codes !== [] ? implode(', ', $codes) : $default)
                . (Multilanguage::isEnabled() ? ', multilingual on' : ', multilingual off'),
            'Database prefix' => (string) $app->get('dbprefix', ''),
        ];
    }

    /**
     * The default template style of one client, as "template (style title)".
     *
     * @param int $clientId 0 for the site, 1 for the administrator
     *
     * @return string 'unknown' when no default style is set
     * @since  1.10.0
     */
    private function homeTemplate(int $clientId): string
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select($db->quoteName(['template', 'title']))
            ->from($db->quoteName('#__template_styles'))
            ->where($db->quoteName('client_id') . ' = ' . $clientId)
            ->where($db->quoteName('home') . ' = ' . $db->quote('1'));

        $style = $db->setQuery($query, 0, 1)->loadObject();

        return $style ? $style->template . ' (' . $style->title . ')' : 'unknown';
    }

    /**
     * The same facts as plain text, ready to paste into a support request.
     *
     * @param string $installed Installed version
     * @param string $registered Version in the extension registry
     * @param string $update Newer release recorded, '' for none
     * @param string $lastCheck When the update server was last queried, '' for never
     * @param array<string, string> $env Environment facts
     * @param array<int, array> $requirements PHP extension checks
     * @param array<string, string> $joomla Joomla configuration facts
     * @param object[] $extensions Installed Alfa extensions
     *
     * @since  1.10.0
     */
    private function report(
        string $installed,
        string $registered,
        string $update,
        string $lastCheck,
        array $env,
        array $requirements,
        array $joomla,
        array $extensions,
    ): string {
        $lines = [self::PRODUCT . ' ' . ($installed !== '' ? $installed : 'unknown')];

        if ($registered !== '' && $registered !== $installed) {
            $lines[] = 'Registered version: ' . $registered;
        }

        $lines[] = $update !== '' ? 'Update available: ' . $update : 'Update: none recorded';
        $lines[] = 'Last update check: ' . ($lastCheck !== '' ? $lastCheck : 'never');

        foreach ($env as $label => $value) {
            $lines[] = $label . ': ' . $value;
        }

        $missing = [];

        foreach ($requirements as $req) {
            if (!$req['loaded']) {
                $missing[] = $req['name'] . ($req['required'] ? ' (required)' : ' (optional)');
            }
        }

        $lines[] = 'Missing PHP extensions: ' . ($missing !== [] ? implode(', ', $missing) : 'none');

        foreach ($joomla as $label => $value) {
            $lines[] = $label . ': ' . $value;
        }

        $lines[] = 'Extensions (' . \count($extensions) . '):';

        foreach ($extensions as $row) {
            $lines[] = '  ' . $row->id . '  ' . ($row->version !== '' ? $row->version : '-') . '  ' . ($row->enabled ? 'enabled' : 'disabled');
        }

        return implode("\n", $lines);
    }

    /**
     * One card of the grid.
     *
     * @param string $title The header, already HTML
     * @param string $body The content, already HTML
     * @param bool $padded Whether the content sits in a card body (false for a flush table)
     *
     * @since  1.10.0
     */
    private static function panel(string $title, string $body, bool $padded = true): string
    {
        return '<div class="card h-100"><div class="card-header fw-semibold">' . $title . '</div>'
            . ($padded ? '<div class="card-body">' . $body . '</div>' : $body)
            . '</div>';
    }

    /**
     * One term/value pair of a facts list.
     *
     * @param string $term The label, escaped here
     * @param string $html The value, already HTML
     *
     * @since  1.10.0
     */
    private static function fact(string $term, string $html): string
    {
        return '<dt class="col-sm-4 fw-normal text-muted">' . self::e($term) . '</dt><dd class="col-sm-8 text-break">' . $html . '</dd>';
    }

    /**
     * An anchor; external ones open in a new tab.
     *
     * @param string $url The href
     * @param string $text The visible text, escaped here
     * @param bool $external Whether the target leaves the site
     *
     * @since  1.10.0
     */
    private static function link(string $url, string $text, bool $external = true): string
    {
        return '<a href="' . self::e($url) . '"' . ($external ? ' target="_blank" rel="noopener noreferrer"' : '') . '>'
            . self::e($text) . '</a>';
    }

    /**
     * An anchor styled as a small outline button.
     *
     * @param string $url The href
     * @param string $text The visible text, escaped here
     * @param bool $external Whether the target leaves the site
     *
     * @since  1.10.0
     */
    private static function button(string $url, string $text, bool $external = true): string
    {
        return '<a class="btn btn-outline-secondary btn-sm" href="' . self::e($url) . '"'
            . ($external ? ' target="_blank" rel="noopener noreferrer"' : '') . '>' . self::e($text) . '</a>';
    }

    /**
     * HTML-escape a value for output.
     *
     * @param string $text The raw text
     *
     * @since  1.10.0
     */
    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
