<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View\Series;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Admin\AlfaAdminContext;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\View\HasAdminEvents;
use Alfa\Component\Alfa\Administrator\View\HtmlView as BaseHtmlView;
use Exception;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * Document series edit view.
 *
 * @since  2.0.0
 */
class HtmlView extends BaseHtmlView
{
    use HasAdminEvents;

    protected $state;

    protected $item;

    protected $form;

    /**
     * This view is the series EDIT screen: the parent is the shared alfa-app
     * wrapper (not FormView), so the contract must be told the screen identity.
     *
     *
     * @since  2.0.0
     */
    protected function alfaScreen(): string
    {
        return AlfaAdminContext::SCREEN_EDIT;
    }

    /**
     * Display the view.
     *
     * @param string $tpl Template name.
     *
     * @return void
     *
     * @since  2.0.0
     */
    public function display($tpl = null)
    {
        // The trait owns the $alfaEntity property; redeclaring it here is a composition
        // fatal, so the entity is assigned, not declared.
        $this->alfaEntity = 'series';

        $this->state = $this->get('State');
        $this->item = $this->get('Item');
        $this->form = $this->get('Form');

        if (\count($errors = $this->get('Errors'))) {
            throw new Exception(implode("\n", $errors));
        }

        $this->addToolbar();

        parent::display($tpl);
    }

    /**
     * Add the page title and toolbar.
     *
     * @return void
     *
     * @since  2.0.0
     */
    protected function addToolbar()
    {
        $isNew = ((int) ($this->item->id ?? 0) === 0);
        $toolbar = $this->getDocument()->getToolbar();

        ToolbarHelper::title(
            Text::_($isNew ? 'COM_ALFA_SERIES_TITLE_NEW' : 'COM_ALFA_SERIES_TITLE_EDIT'),
            'generic',
        );

        $user = $this->getCurrentUser();
        $canEdit = AlfaHelper::can(section: 'documents', action: 'edit', user: $user);
        $canSave = $isNew ? AlfaHelper::can(section: 'documents', action: 'create', user: $user) : $canEdit;

        // Apply reopens the saved series, which needs edit even when the series is new.
        if ($canSave && $canEdit) {
            $toolbar->apply('series.apply');
        }

        // Saving a new series is create, saving an existing one is edit.
        if ($canSave) {
            $toolbar->save('series.save');
        }

        $toolbar->cancel('series.cancel', $isNew ? 'JTOOLBAR_CANCEL' : 'JTOOLBAR_CLOSE');
    }
}
