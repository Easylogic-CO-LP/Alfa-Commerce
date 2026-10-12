<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View\Documents;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\DocumentHelper;
use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use Alfa\Component\Alfa\Administrator\Helper\TourHelper;
use Alfa\Component\Alfa\Administrator\View\AlfaHtmlView as BaseHtmlView;
use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * Documents list view: every issued legal document across all types.
 *
 * No "New" button by design: documents are issued FROM an order (order
 * edit -> Documents panel) or by status automation, never from a blank
 * form. The toolbar links to the series manager instead.
 *
 * @since  1.3.0
 */
class HtmlView extends BaseHtmlView
{
    /**
     * The admin-contract entity a plugin targets with $context->targets('document').
     *
     *
     * @since  1.8.0
     */
    protected string $alfaEntity = 'document';

    protected $items;

    protected $pagination;

    protected $state;

    public $filterForm;

    public $activeFilters;

    /**
     * The document row the void modal acts on (void layouts only).
     *
     * NOT named $document: BaseHtmlView already owns that property and it
     * holds Joomla's Document, which every layout needs for its assets.
     *
     * @var object|null
     *
     * @since  1.3.0
     */
    public $voidDocument;

    /**
     * The void form (void layout only).
     *
     * @var \Joomla\CMS\Form\Form|null
     *
     * @since  1.3.0
     */
    public $form;

    /**
     * Display the view.
     *
     * @param string $tpl Template name.
     *
     * @return void
     *
     * @since  1.3.0
     */
    public function display($tpl = null)
    {
        $model = $this->getModel();

        // The void modal, and the page it returns to
        // Its own request, in an iframe, exactly like the order screen's
        // popups. It loads no list: the only thing it needs is the document
        // it is about to void.
        if ($this->getLayout() === 'void' || $this->getLayout() === 'void_return') {
            $documentId = (int) Factory::getApplication()->getInput()->getInt('id', 0);

            // Voiding changes a document's STATUS, never its content: the
            // same permission the task itself demands. Refused here before a
            // form is built, so the modal is never offered to someone whose
            // submit would be turned away.
            if (!AlfaHelper::can(section: 'documents', action: 'edit.state', user: $this->getCurrentUser())) {
                throw new Exception(Text::_('JERROR_ALERTNOAUTHOR'), 403);
            }

            $this->voidDocument = DocumentHelper::getById($documentId);

            if (!$this->voidDocument) {
                throw new Exception(Text::_('COM_ALFA_DOCUMENTS_NOT_FOUND'), 404);
            }

            if ($this->getLayout() === 'void') {
                $this->form = $model->getVoidForm($documentId);

                // The reason a refused void kept (DocumentsController::void()):
                // read back ONLY on the redisplay the controller asked for
                // (kept=1). Any other open (the list's or the order's Void
                // button, or after the dialog was closed by its X) forgets it.
                $app = Factory::getApplication();
                $stateKey = 'com_alfa.edit.documents.void.' . $documentId;

                if ($app->getInput()->getInt('kept', 0) !== 1) {
                    $app->setUserState($stateKey, null);
                }

                $kept = (array) $app->getUserState($stateKey, []);

                if ($this->form && isset($kept['reason'])) {
                    $this->form->setValue('reason', null, (string) $kept['reason']);
                }

                $this->addVoidToolbar();
            }

            parent::display($tpl);

            return;
        }

        $this->items = $model->getItems();
        $this->pagination = $model->getPagination();
        $this->state = $model->getState();
        $this->filterForm = $model->getFilterForm();
        $this->activeFilters = $model->getActiveFilters();

        $this->addToolbar();

        $this->filterForm
            ->addControlField('task', '')
            ->addControlField('boxchecked', '0');

        parent::display($tpl);
    }

    /**
     * Toolbar for the void modal.
     *
     * One write and one way out. There is no Delete: a document is never
     * removed, which is the whole reason voiding exists.
     *
     *
     * @since   1.3.0
     */
    protected function addVoidToolbar(): void
    {
        Factory::getApplication()->getInput()->set('hidemainmenu', true);

        $toolbar = $this->getDocument()->getToolbar();

        ToolbarHelper::title(Text::_('COM_ALFA_DOCUMENTS_ACTION_VOID'), 'generic');

        $toolbar->save('documents.void')
            ->text('COM_ALFA_DOCUMENTS_ACTION_VOID')
            ->icon('icon-cancel-circle');

        $toolbar->cancel('documents.cancelVoid');
    }

    /**
     * Add the page title and toolbar.
     *
     * @return void
     *
     * @since  1.3.0
     */
    protected function addToolbar()
    {
        $user = $this->getCurrentUser();
        $toolbar = $this->getDocument()->getToolbar();

        ToolbarHelper::title(Text::_('COM_ALFA_DOCUMENTS_TITLE'), 'generic');

        $toolbar->linkButton('documentseries')
            ->text('COM_ALFA_DOCUMENTSERIES_TITLE')
            ->url(Route::_('index.php?option=com_alfa&view=documentseries'))
            ->icon('icon-cog');

        $toolbar->linkButton('documenttypes')
            ->text('COM_ALFA_DOCUMENTTYPES_TITLE')
            ->url(Route::_('index.php?option=com_alfa&view=documenttypes'))
            ->icon('icon-tags-2');

        // Options opens com_config, which itself requires core.admin.
        if ($user->authorise('core.admin', 'com_alfa')) {
            $toolbar->preferences('com_alfa');
        }

        TourHelper::toolbarHelp($toolbar, $this->getName());

        NotificationHelper::toolbarBadge($toolbar);
    }
}
