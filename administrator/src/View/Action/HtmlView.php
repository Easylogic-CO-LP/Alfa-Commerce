<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View\Action;

// No direct access
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\ActionRegistry;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use RuntimeException;

/**
 * GENERIC backend action page (view=action, tmpl=component), the backend twin
 * of the frontend Item/Cart views: it boots the right plugin, dispatches the
 * action event ONCE and renders whatever it produced. Any group (payments,
 * shipments, item fields) flows through here, so a plugin only ever does
 * setLayout()/setLayoutData() (a UI) or setMessage()/setRefresh() (a result):
 *
 *   - setLayout()        -> tmpl/action/default.php echoes that layout, on a real
 *                          page, so its useScript()/useStyle() load normally
 *                          (a panel, a form, or a full three.js editor).
 *   - message / refresh  -> tmpl/action/notice.php shows the message and posts a
 *                          close/reload back to the opener.
 *   - setRedirectUrl()   -> we redirect, exactly like the frontend views.
 *
 * The action runs exactly once (here): no AJAX pre-dispatch, no re-dispatch.
 *
 * Security: the edit permissions of what the action works on
 * (ActionRegistry::actionPagePermissions), asked before anything is dispatched; the
 * group is validated inside resolveActionPage, the layout is the plugin's to choose
 * (never the URL's).
 *
 * @since  2.0.0
 */
class HtmlView extends BaseHtmlView
{
    /**
     * The dispatched action event (carries layout/data or message/refresh).
     *
     * @var object
     *
     * @since  2.0.0
     */
    protected $actionEvent;

    /**
     * The plugin group ('alfa-item-fields', 'alfa-payments', 'alfa-shipments').
     *
     * @var string
     *
     * @since  2.0.0
     */
    protected $group = '';

    /**
     * The plugin element name (the action's layout owner).
     *
     * @var string
     *
     * @since  2.0.0
     */
    protected $type = '';

    /**
     * Resolve + dispatch the action once, then render its result as a page.
     *
     * @param string $tpl The template name (ignored; chosen from the event).
     *
     * @return void
     *
     * @throws Exception
     *
     * @since  2.0.0
     */
    public function display($tpl = null)
    {
        $app = Factory::getApplication();

        // The group decides the permissions: an action runs only for someone who may edit
        // everything it works on (an order's rows, the item a field belongs to, or both).
        $permissions = ActionRegistry::actionPagePermissions($app->getInput());

        if ($permissions === null) {
            throw new RuntimeException('Unsupported action group: ' . $app->getInput()->getCmd('group', ''), 400);
        }

        foreach ($permissions as $permission) {
            if (!AlfaHelper::can(section: $permission['section'], action: $permission['action'], user: $app->getIdentity())) {
                throw new RuntimeException(Text::_('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN'), 403);
            }
        }

        // This view DISPATCHES a state-changing plugin action, so it must not be
        // reachable by a bare GET / CSRF: the action buttons (actions.js) carry
        // the CSRF token in the query.
        if (!\Joomla\CMS\Session\Session::checkToken('request')) {
            throw new RuntimeException(Text::_('JINVALID_TOKEN'), 403);
        }

        // ActionRegistry boots the right plugin, loads the group's context and
        // dispatches its execute event ONCE: one place for every group.
        $resolved = ActionRegistry::resolveActionPage($app->getInput());

        $this->group = $resolved['group'];
        $this->type = $resolved['type'];
        $this->actionEvent = $resolved['event'];

        // Every action layout gets the AlfaAction helper (close / reload / run).
        $app->getDocument()->getWebAssetManager()->useScript('com_alfa.action-page');

        // A follow-up arm (an amount form submitting its value) cannot rebuild
        // its request from the URL: a state-changing action arrives by POST, so
        // there is no query string to read. Hand the context to JS instead; an
        // item field's follow-up runs on the row its popup was opened for.
        $input = $app->getInput();
        $app->getDocument()->addScriptOptions('alfa.action.context', [
            'group' => $input->getCmd('group', ''),
            'payment_id' => $input->getInt('payment_id', 0),
            'shipment_id' => $input->getInt('shipment_id', 0),
            'field_id' => $input->getInt('field_id', 0),
            'scope' => $input->getCmd('scope', ''),
            'scope_id' => $input->getInt('scope_id', 0),
            'item_id' => $input->getInt('item_id', 0),
            'token' => \Joomla\CMS\Session\Session::getFormToken(),
        ]);

        // A plugin may ask to leave the page entirely (like the frontend views).
        if (
            method_exists($this->actionEvent, 'getRedirectUrl')
            && ($url = $this->actionEvent->getRedirectUrl())
        ) {
            $app->redirect($url);

            return;
        }

        // A layout -> render it (editor, form, logs); otherwise the result
        // notice (message + refresh). Footer buttons (addButton) render on top
        // of either, so a confirm/wizard needs no special layout name.
        $this->setLayout($this->actionEvent->getLayout() ? 'default' : 'notice');

        parent::display($tpl);
    }
}
