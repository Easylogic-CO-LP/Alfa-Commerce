<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;

/**
 * Alfa master display controller.
 *
 * @since  1.0.0
 */
class DisplayController extends BaseController
{
    /**
     * The default view, the Dashboard: opening "Commerce" lands on the home screen
     * (setup checklist, later quick stats), not on a bare list.
     *
     * @var string
     * @since  1.0.0
     */
    protected $default_view = 'dashboard';

    /**
     * Method to display a view.
     *
     * @param bool $cachable If true, the view output will be cached
     * @param array $urlparams An array of safe URL parameters and their variable types, for valid values see {@link InputFilter::clean()}.
     *
     * @return BaseController|bool This object to support chaining.
     *
     * @since  1.0.0
     */
    public function display($cachable = false, $urlparams = [])
    {
        if ($this->refuseUnheldRecord()) {
            return false;
        }

        return parent::display();
    }

    /**
     * Refuse a record's form for an id the edit task did not hold for this operator, as Joomla's own
     * components do. The edit task checks the record out and clears its kept post, so a direct link,
     * a bookmark or Back after Close lands on the record's list with Joomla's message. A new record
     * (no id) is not refused. View, layout and id are read as the dispatcher reads them.
     *
     * @return bool True when the request was refused and redirected.
     *
     * @since  2.0.0
     */
    private function refuseUnheldRecord(): bool
    {
        $view = strtolower((string) $this->input->getCmd('view', $this->default_view));
        $layout = $this->input->get('layout', '', 'string');

        if (!AlfaHelper::isRecordForm(view: $view, layout: \is_string($layout) ? $layout : '')) {
            return false;
        }

        $id = AlfaHelper::recordId(view: $view, input: $this->input);

        if ($this->checkEditId(context: 'com_alfa.edit.' . $view, id: $id)) {
            return false;
        }

        // The edit task's failed check-out keeps its own reason; nothing else queued (the
        // integrity banner, another extension's notice) stands in for Joomla's message.
        if (!$this->checkoutRefusalQueued()) {
            $this->setMessage(text: Text::sprintf('JLIB_APPLICATION_ERROR_UNHELD_ID', $id), type: 'error');
        }

        $this->setRedirect(url: Route::_(url: 'index.php?option=com_alfa&view=' . $this->recordList(view: $view), xhtml: false));

        return true;
    }

    /**
     * Whether the edit task's own check-out refusal is queued: core's edit() sends a failed
     * check-out to the record's form with it (FormController::attemptCheckout()), and it is the
     * reason the operator reads there. Any other queued message, such as the integrity banner the
     * dispatcher queues on every page, is not a reason for this refusal.
     *
     *
     * @since  2.0.0
     */
    private function checkoutRefusalQueued(): bool
    {
        // Core's sentence up to the model's own error.
        $refusal = explode(separator: '%s', string: Text::_('JLIB_APPLICATION_ERROR_CHECKOUT_FAILED'), limit: 2)[0];

        foreach ($this->app->getMessageQueue() as $queued) {
            if (str_starts_with(haystack: (string) ($queued['message'] ?? ''), needle: $refusal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The list a record screen belongs to: its own controller's view_list (series: documentseries).
     *
     * @param string $view A record screen.
     *
     *
     * @since  2.0.0
     */
    private function recordList(string $view): string
    {
        $controller = $this->factory->createController(name: $view, prefix: 'Administrator', config: [], app: $this->app, input: $this->input);

        return (fn (): string => (string) $this->view_list)->call($controller);
    }
}
