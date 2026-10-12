<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Admin;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Closure;
use Joomla\CMS\Uri\Uri;
use Joomla\Input\Input;

/**
 * Fluent descriptor for ONE plugin-declared list action: either a per-row action (the buttons
 * beside a row) or a bulk action (applied to the checked rows). Both are the same shape: a
 * labelled thing that submits a task, so they share one builder and differ only in the event
 * that collects them.
 *
 *     $context->actions->action('resend')
 *         ->title('PLG_ALFA_NOTIFY_RESEND')
 *         ->icon('icon-mail')
 *         ->task('notify.resend')
 *         ->access('alfa.notify.resend')
 *         ->confirm('PLG_ALFA_NOTIFY_RESEND_CONFIRM');
 *
 * It deliberately mirrors {@see \Alfa\Component\Alfa\Administrator\Event\Lists\ColumnBuilder}:
 * layout + data, never HTML, with the layout owner defaulting to the contributing plugin. An
 * action that sets a layout renders through that layout instead of the default button, for the
 * cases a plain button cannot express.
 *
 * TASKS ARE POSTED, NEVER LINKED. A declared task submits the admin list form, so it arrives
 * with the CSRF token the screen already carries; that is the whole write boundary, the same
 * one every native Joomla list action uses. Use url() only for navigation (a report, another
 * screen), never to change state.
 *
 * PERMISSIONS. An action is shown only to a user who holds the permission it needs
 * ({@see self::permission()}): the one the plugin declares with access(), otherwise the one Alfa
 * works out from where the action goes. The task it submits still checks for itself: hiding a
 * control is not what protects the data.
 *
 * @since  1.8.0
 */
final class ActionBuilder
{
    /**
     * @var string Label language key.
     * @since  1.8.0
     */
    private string $title = '';

    /**
     * @var string Icon class (Joomla's icomoon set, e.g. 'icon-refresh').
     * @since  1.8.0
     */
    private string $icon = '';

    /**
     * @var string The controller task the action submits.
     * @since  1.8.0
     */
    private string $task = '';

    /**
     * @var string A navigation URL, for actions that only go somewhere.
     * @since  1.8.0
     */
    private string $url = '';

    /**
     * @var string Confirmation prompt language key ('' = act immediately).
     * @since  1.8.0
     */
    private string $confirm = '';

    /**
     * @var string Extra CSS classes for the rendered control.
     * @since  1.8.0
     */
    private string $cssClass = '';

    /**
     * @var string The layout that renders the action ('' = the default button).
     * @since  1.8.0
     */
    private string $layout = '';

    /**
     * @var Closure|null Per-row data resolver: fn (object $row): array|object|null.
     * @since  1.8.0
     */
    private ?Closure $data = null;

    /**
     * @var string The plugin group owning the layout.
     * @since  1.8.0
     */
    private string $pluginType = '';

    /**
     * @var string The plugin element owning the layout.
     * @since  1.8.0
     */
    private string $pluginName = '';

    /**
     * @var string The permission the plugin declared ('' = let Alfa work it out).
     * @since  1.10.0
     */
    private string $access = '';

    /**
     * @var string The asset the declared permission is asked on.
     * @since  1.10.0
     */
    private string $accessAsset = 'com_alfa';

    /**
     * @param string $key The action's identity key (unique per contributor).
     *
     * @since  1.8.0
     */
    public function __construct(private readonly string $key)
    {
    }

    /**
     * Set the label language key.
     *
     * @param string $title Language constant.
     *
     * @return static For chaining.
     *
     * @since  1.8.0
     */
    public function title(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    /**
     * Set the icon class.
     *
     * @param string $icon Icon class, e.g. 'icon-refresh'.
     *
     * @return static For chaining.
     *
     * @since  1.8.0
     */
    public function icon(string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    /**
     * Set the controller task this action submits.
     *
     * @param string $task The task, e.g. 'orders.resend'.
     *
     * @return static For chaining.
     *
     * @since  1.8.0
     */
    public function task(string $task): static
    {
        $this->task = $task;

        return $this;
    }

    /**
     * Set a navigation URL. For going somewhere only; a state change must use task().
     *
     * @param string $url The URL, unescaped: the renderer escapes it. An escaped one (Route::_() with
     *                    its xhtml flag on) is decoded first.
     *
     * @return static For chaining.
     *
     * @since  1.8.0
     */
    public function url(string $url): static
    {
        // Escaped, it would read as 'amp;view' when its permission is worked out, and render escaped twice.
        $this->url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $this;
    }

    /**
     * Require a confirmation before the action runs.
     *
     * @param string $message Confirmation language key.
     *
     * @return static For chaining.
     *
     * @since  1.8.0
     */
    public function confirm(string $message): static
    {
        $this->confirm = $message;

        return $this;
    }

    /**
     * Add CSS classes to the rendered control.
     *
     * @param string $cssClass Space-separated classes.
     *
     * @return static For chaining.
     *
     * @since  1.8.0
     */
    public function cssClass(string $cssClass): static
    {
        $this->cssClass = $cssClass;

        return $this;
    }

    /**
     * Name the permission a user needs to see this action, usually one the plugin registered on
     * onAlfaGetAccessActions (Options -> Permissions), or an Alfa one such as alfa.orders.edit.
     *
     * Declare it whenever the action needs something other than what Alfa would work out from its
     * task or link, and always for an action Alfa cannot work one out for (a plugin's own task, a
     * panel link, a layout-only action); without it such an action is not shown at all.
     *
     * @param string $action The permission, e.g. 'alfa.demopack.actions'.
     * @param string $asset The asset it is asked on.
     *
     * @return static For chaining.
     *
     * @since  1.10.0
     */
    public function access(string $action, string $asset = 'com_alfa'): static
    {
        $this->access = trim($action);
        $this->accessAsset = $asset !== '' ? $asset : 'com_alfa';

        return $this;
    }

    /**
     * Render the action through a layout instead of the default button.
     *
     * @param string $layout Layout name (without .php), in the owner plugin's tmpl folder.
     *
     * @return static For chaining.
     *
     * @since  1.8.0
     */
    public function setLayout(string $layout): static
    {
        $this->layout = $layout;

        return $this;
    }

    /**
     * Set the per-row data resolver for a layout-rendered action. Called once per row at
     * render time; returning null renders nothing for that row. ROW ACTIONS ONLY: the bulk
     * bar has no row, so there a layout receives the plain action descriptor instead.
     *
     * @param Closure $data fn (object $row): array|object|null.
     *
     * @return static For chaining.
     *
     * @since  1.8.0
     */
    public function setData(Closure $data): static
    {
        $this->data = $data;

        return $this;
    }

    /**
     * Declare the plugin group that owns the layout.
     *
     * @param string $type The plugin group.
     *
     * @return static For chaining.
     *
     * @since  1.8.0
     */
    public function setPluginType(string $type): static
    {
        $this->pluginType = $type;

        return $this;
    }

    /**
     * Declare the plugin element that owns the layout.
     *
     * @param string $name The plugin element.
     *
     * @return static For chaining.
     *
     * @since  1.8.0
     */
    public function setPluginName(string $name): static
    {
        $this->pluginName = $name;

        return $this;
    }

    /**
     * Whether the action can do anything at all: it must submit a task, go somewhere, or
     * render through its own layout (whose markup carries its own controls). An action with
     * none of the three is dropped rather than rendered as a dead control.
     *
     *
     * @since  1.8.0
     */
    public function isActionable(): bool
    {
        return $this->task !== '' || $this->url !== '' || $this->layout !== '';
    }

    /**
     * The permission a user needs to see this action.
     *
     * In order: the permission the plugin declared with access(); else what the task it submits
     * needs, when Alfa can vouch for that task ({@see AlfaHelper::taskPermission()}); else, for a
     * link, what following it needs ({@see self::linkPermission()}). Anything else (a plugin's own
     * task, a task Alfa does not know, a self-guarded screen, a layout-only action) cannot be known,
     * and is not shown until the plugin declares its permission.
     *
     * @return array{action: string, asset: string}|array{section: string, action: string}|array{screen: string, layout: string, id: int}|array{}|null
     *                                                                                                                                                 The declared permission (action + asset), a section permission for AlfaHelper::can(),
     *                                                                                                                                                 a screen for AlfaHelper::mayOpenScreen(), an empty array when nothing is needed, or
     *                                                                                                                                                 null when nothing can tell.
     *
     * @since  1.10.0
     */
    public function permission(): ?array
    {
        if ($this->access !== '') {
            return ['action' => $this->access, 'asset' => $this->accessAsset];
        }

        if ($this->task !== '') {
            return AlfaHelper::taskPermission($this->task);
        }

        if ($this->url !== '') {
            return self::linkPermission($this->url);
        }

        // Only a layout: nothing to work a permission out from.
        return null;
    }

    /**
     * What following a link needs, read the way the administrator reads the request it becomes.
     *
     * A page outside the administrator, or outside Alfa, needs nothing here: it guards itself. A task
     * the controller runs needs what the task needs. An Alfa page, any request that ends in display,
     * including a controller named without a task (its page is the controller's own) and a task the
     * controller does not run (it falls through to display), opens by the answer the dispatcher
     * gives it. A self-guarded screen checks a permission of its own that a link cannot know.
     *
     * @param string $url The link.
     *
     * @return array{section: string, action: string}|array{screen: string, layout: string, id: int}|array{}|null
     *
     * @since  1.10.0
     */
    private static function linkPermission(string $url): ?array
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return null;
        }

        // Only a request to this administrator is an admin screen: a storefront page, or another
        // site's, guards itself. With a host, only the administrator's own path counts; without
        // one, also a link relative to the administrator page it is drawn on.
        $host = strtolower($parts['host'] ?? '');

        // As the server resolves it: decoded, repeated slashes collapsed, and anything after the entry
        // point handed to it as PATH_INFO.
        $path = (string) preg_replace('#//+#', '/', rawurldecode($parts['path'] ?? ''));
        $path = (string) preg_replace('#(^|/)index\.php/.*$#i', '$1index.php', $path);
        $admin = preg_match('#(^|/)administrator/(index\.php)?$#i', $path)
            || ($host === '' && preg_match('#^(\./)?(index\.php)?$#i', $path));

        if (($host !== '' && $host !== strtolower(Uri::getInstance()->getHost())) || !$admin) {
            return [];
        }

        $query = [];

        parse_str($parts['query'] ?? '', $query);

        // Joomla's own filters, as the administrator applies them to the request.
        $input = new Input($query);

        if (strtolower($input->getCmd('option', '')) !== 'com_alfa') {
            return [];
        }

        // As ComponentDispatcher::dispatch(): controller.task, else a named controller and a bare task.
        $command = strtolower($input->getCmd('task', 'display'));
        $controller = strtolower($input->getCmd('controller', 'display'));
        $task = $command;

        if (str_contains($command, '.')) {
            [$controller, $task] = explode('.', $command);
        }

        if ($task !== '' && $task !== 'display' && AlfaHelper::controllerRuns($controller, $task) !== false) {
            return AlfaHelper::taskPermission($controller . '.' . $task);
        }

        $view = strtolower($input->getCmd('view', '')) ?: ($controller === 'display' ? 'dashboard' : $controller);

        if (\in_array($view, AlfaHelper::SELF_GUARDED_SCREENS, true)) {
            return null;
        }

        $layout = $input->get('layout', '', 'string');

        return ['screen' => $view, 'layout' => \is_string($layout) ? $layout : '', 'id' => AlfaHelper::recordId(view: $view, input: $input)];
    }

    /**
     * The plain action descriptor the list template renders from.
     *
     * @return array{key: string, title: string, icon: string, task: string, url: string,
     *               confirm: string, cssClass: string, layout: string, layoutPluginType: string,
     *               layoutPluginName: string, data: ?Closure, access: string}
     *
     * @since  1.8.0
     */
    public function toDescriptor(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'icon' => $this->icon,
            'task' => $this->task,
            'url' => $this->url,
            'confirm' => $this->confirm,
            'cssClass' => $this->cssClass,
            'layout' => $this->layout,
            'layoutPluginType' => $this->pluginType,
            'layoutPluginName' => $this->pluginName,
            'data' => $this->data,
            'access' => $this->access,
        ];
    }
}
