<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View\Webhook;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Admin\AlfaAdminContext;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\View\HasAdminEvents;
use Alfa\Component\Alfa\Administrator\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * A webhook's edit screen.
 *
 * @since  2.3.0
 */
class HtmlView extends BaseHtmlView
{
    use HasAdminEvents;

    /**
     * The form.
     *
     * @var \Joomla\CMS\Form\Form
     *
     * @since  2.3.0
     */
    protected $form;

    /**
     * The webhook.
     *
     * @var object
     *
     * @since  2.3.0
     */
    protected $item;

    /**
     * The model's state.
     *
     * @var \Joomla\Registry\Registry
     *
     * @since  2.3.0
     */
    protected $state;

    /**
     * The admin-contract screen kind.
     *
     * @since  2.3.0
     */
    protected function alfaScreen(): string
    {
        return AlfaAdminContext::SCREEN_EDIT;
    }

    /**
     * Display the screen.
     *
     * @param string|null $tpl The template.
     *
     * @since  2.3.0
     */
    public function display($tpl = null)
    {
        $this->alfaEntity = 'webhook';

        $model = $this->getModel();

        $this->form = $model->getForm();
        $this->item = $model->getItem();
        $this->state = $model->getState();

        $this->addToolbar();

        parent::display($tpl);
    }

    /**
     * The toolbar.
     *
     * @since  2.3.0
     */
    protected function addToolbar()
    {
        $this->getDocument()->getToolbar();

        $isNew = (int) ($this->item->id ?? 0) === 0;

        ToolbarHelper::title(Text::_($isNew ? 'COM_ALFA_WEBHOOK_TITLE_NEW' : 'COM_ALFA_WEBHOOK_TITLE_EDIT'), 'generic');

        $user = $this->getCurrentUser();
        $canEdit = AlfaHelper::can(section: 'webhooks', action: 'edit', user: $user);
        $canSave = $isNew ? AlfaHelper::can(section: 'webhooks', action: 'create', user: $user) : $canEdit;

        if ($canSave && $canEdit) {
            ToolbarHelper::apply('webhook.apply');
        }

        if ($canSave) {
            ToolbarHelper::save('webhook.save');
        }

        ToolbarHelper::cancel('webhook.cancel', $isNew ? 'JTOOLBAR_CANCEL' : 'JTOOLBAR_CLOSE');
    }
}
