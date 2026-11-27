<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View\Discount;

// No direct access
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\ErrorText;
use Alfa\Component\Alfa\Administrator\Helper\RequestScalar;
use Alfa\Component\Alfa\Administrator\Service\Pricing\DiscountExclusions;
use Alfa\Component\Alfa\Administrator\Service\Pricing\DiscountReach;
use Alfa\Component\Alfa\Administrator\View\AlfaFormView as FormView;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\Database\DatabaseInterface;
use Throwable;

/**
 * View class for a single Category.
 *
 * @since  1.0.0
 */
class HtmlView extends FormView
{
    /**
     * @var string The entity a plugin targets with $context->targets('discount').
     * @since  2.0.0
     */
    protected string $alfaEntity = 'discount';

    /**
     * What the saved rule reaches, for the form's Reach card (DiscountReach::of()); null for a new rule, or when the
     * counts could not be read (logged, the form stands).
     *
     * @var array|null
     *
     * @since  2.3.0
     */
    public ?array $reach = null;

    /**
     * How many products the saved rule's scope takes in and never reaches: excluded by the rule, and excluded on their
     * own (DiscountExclusions::counts()); null for a new rule, or when the counts could not be read (logged).
     *
     * @var array{by_rule: int, own: int}|null
     *
     * @since  2.3.0
     */
    public ?array $exclusions = null;

    /**
     * Render the edit form view.
     *
     * @param string|null $tpl The name of the template file to parse
     *
     * @return void
     *
     * @since  1.0.0
     */
    public function display($tpl = null)
    {
        parent::display($tpl);
    }

    /**
     * Prepare the view: add the 'task' and base64 'return' control fields to the form.
     *
     * @return void
     *
     * @since  1.0.0
     */
    protected function initializeView()
    {
        parent::initializeView();

        $input = Factory::getApplication()->getInput();

        // Add form control fields
        $this->form
            ->addControlField('task', '')
            ->addControlField('return', RequestScalar::read($input, 'return', '', 'base64'));

        // The Reach card and the exclusion counts are the saved rule's; a rule not saved yet has nothing to count.
        $discountId = (int) ($this->item->id ?? 0);

        if ($discountId > 0) {
            try {
                $this->reach = (new DiscountReach(db: Factory::getContainer()->get(DatabaseInterface::class)))->of(discountId: $discountId);
            } catch (Throwable $e) {
                Log::add('Discount #' . $discountId . ': the Reach card could not be counted: ' . $e::class . ': ' . ErrorText::of($e), Log::WARNING, 'com_alfa');
                $this->reach = null;
            }

            try {
                $this->exclusions = (new DiscountExclusions(db: Factory::getContainer()->get(DatabaseInterface::class)))->counts(discountId: $discountId);
            } catch (Throwable $e) {
                Log::add('Discount #' . $discountId . ': the exclusion counts could not be read: ' . $e::class . ': ' . ErrorText::of($e), Log::WARNING, 'com_alfa');
                $this->exclusions = null;
            }
        }
    }

    /**
     * Build the edit toolbar: lock the main menu and add Apply/Save, Save & New,
     * Save as Copy and Cancel buttons according to the current user permissions.
     *
     * @return void
     *
     * @since  1.0.0
     */
    protected function addToolbar()
    {
        Factory::getApplication()->getInput()->set('hidemainmenu', true);

        $user = $this->getCurrentUser();
        $userId = $user->id;
        $isNew = ($this->item->id == 0);
        $checkedOut = !(\is_null($this->item->checked_out) || $this->item->checked_out == $userId);

        $canCreate = AlfaHelper::can(section: 'discounts', action: 'create', user: $user);
        $canEdit = AlfaHelper::can(section: 'discounts', action: 'edit', user: $user);

        ToolbarHelper::title(Text::_('COM_ALFA_TITLE_DISCOUNT'), 'generic');

        // Apply reopens the saved record, which needs edit even when the record is new.
        if (!$checkedOut && ($isNew ? ($canCreate && $canEdit) : $canEdit)) {
            ToolbarHelper::apply('discount.apply', 'JTOOLBAR_APPLY');
        }

        // If not checked out, can save the item: a new record needs create, an existing one edit.
        if (!$checkedOut && ($isNew ? $canCreate : $canEdit)) {
            ToolbarHelper::save('discount.save', 'JTOOLBAR_SAVE');
        }

        if (!$checkedOut && $canCreate) {
            ToolbarHelper::custom('discount.save2new', 'save-new.png', 'save-new_f2.png', 'JTOOLBAR_SAVE_AND_NEW', false);
        }

        // Save as Copy reopens the new copy, so it needs edit as well as create.
        if (!$isNew && $canCreate && $canEdit) {
            ToolbarHelper::custom('discount.save2copy', 'save-copy.png', 'save-copy_f2.png', 'JTOOLBAR_SAVE_AS_COPY', false);
        }

        ToolbarHelper::cancel('discount.cancel', 'JTOOLBAR_CANCEL');
    }
}
