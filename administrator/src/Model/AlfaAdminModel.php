<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Model;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Card\CardRegistry;
use Alfa\Component\Alfa\Administrator\Event\Admin\AdminAfterDeleteEvent;
use Alfa\Component\Alfa\Administrator\Event\Admin\AdminAfterSaveEvent;
use Alfa\Component\Alfa\Administrator\Event\Admin\AdminBeforeDeleteEvent;
use Alfa\Component\Alfa\Administrator\Event\Admin\AdminBeforeSaveEvent;
use Alfa\Component\Alfa\Administrator\Event\Admin\AdminFormEvent;
use Alfa\Component\Alfa\Administrator\Event\Admin\AdminPrepareDataEvent;
use Alfa\Component\Alfa\Administrator\Event\Admin\AdminValidateEvent;
use Alfa\Component\Alfa\Administrator\Event\Admin\AlfaAdminAware;
use Alfa\Component\Alfa\Administrator\Event\Admin\AlfaAdminContext;
use Alfa\Component\Alfa\Administrator\Event\Admin\AlfaAdminDispatcher;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Helper\SetupHelper;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\AdminModel;
use Joomla\CMS\Table\Table;
use Throwable;

/**
 * The edit half of com_alfa's admin extension contract: an entity's record model gains the form,
 * data, validate, save and delete hooks by changing ONE word (the class it extends) and
 * declaring its entity name.
 *
 *     final class CurrencyModel extends AlfaAdminModel
 *     {
 *         protected string $alfaEntity = 'currency';
 *         // ...everything else unchanged...
 *     }
 *
 * WHY THESE SEAMS. All 21 com_alfa record models override getForm() and loadFormData(), so
 * wrapping either would put the hook where a subclass has already replaced it. preprocessForm(),
 * loadForm() and validate() are overridden by NONE of them, which is what lets an entity gain the
 * form, data and veto hooks without a line moving inside it. save() and delete() are different:
 * most models do override them, but they all reach their parent, so the wrapper still runs; see
 * WHERE SAVE AND DELETE FIRE below for exactly where that leaves the events.
 *
 *   preprocessForm()  -> onAlfaAdminForm         the funnel every loadForm() passes through, before
 *                       onAlfaAdminPrepareData  the record data is bound: schema first, then the
 *                                               values that fill it.
 *   validate()        -> onAlfaAdminValidate     Joomla's own validation point, so a plugin's veto
 *                                               refuses the save exactly the way a failed field
 *                                               rule does: messages shown, form redisplayed.
 *   save()            -> onAlfaAdminBeforeSave   around the component's own persistence.
 *                       onAlfaAdminAfterSave
 *   delete()          -> onAlfaAdminBeforeDelete
 *                       onAlfaAdminAfterDelete
 *
 * WHERE SAVE AND DELETE FIRE, PRECISELY. A subclass that overrides save() reaches this wrapper at
 * its own parent::save() call, so the pair brackets the RECORD WRITE, not the subclass's
 * surrounding work. That is deliberate and it is the same place Joomla puts its own
 * onContentBeforeSave/onContentAfterSave, but it does mean a model doing significant work after
 * its parent:: call has already done some of it by the time before-save fires and not yet done
 * the rest when after-save does. An entity where that distinction matters says so when it is
 * rolled onto the contract.
 *
 * A veto is data: before-save and before-delete collect every objection and refuse cleanly through
 * setError(), which is what the controller already knows how to redisplay.
 *
 * @since  2.0.0
 */
abstract class AlfaAdminModel extends AdminModel
{
    use AlfaAdminAware;

    /**
     * @var array|null Data a contributor changed in the prepare-data phase, waiting for
     *                 {@see self::loadForm()} to bind it over the component's own.
     * @since  2.0.0
     */
    private ?array $alfaPreparedData = null;

    /**
     * May the current user delete records of this entity: its own section's delete permission,
     * never Joomla's core.delete. Every delete path reaches this: the list, the API, and any
     * subclass whose own canDelete() adds business rules on top of its parent.
     *
     * @param object $record The record to delete.
     *
     * @return bool
     *
     * @since  2.0.0
     */
    protected function canDelete($record)
    {
        return AlfaHelper::can(section: $this->alfaEntity, action: 'delete', user: $this->getCurrentUser());
    }

    /**
     * May the current user change the state of records of this entity: its own section's
     * edit.state permission, never Joomla's core.edit.state. Publish, unpublish, archive, trash,
     * reorder and save-order all reach this, and so does the state field of the edit form
     * ({@see self::preprocessForm()}).
     *
     * @param object $record The record whose state would change.
     *
     * @return bool
     *
     * @since  2.0.0
     */
    protected function canEditState($record)
    {
        return AlfaHelper::can(section: $this->alfaEntity, action: 'edit.state', user: $this->getCurrentUser());
    }

    /**
     * Release edit locks: part of editing, so it needs the entity's edit or create permission.
     *
     * Create counts because saving a new record checks it back in straight away. A form closed
     * before its record ever existed has nothing to release and is never refused.
     *
     * @param mixed $pks The primary key or keys to check in.
     *
     * @return int|bool The number checked in, or false when refused or failed.
     *
     * @since  2.0.0
     */
    public function checkin($pks = [])
    {
        $user = $this->getCurrentUser();

        if (
            array_filter((array) $pks) !== []
            && !AlfaHelper::can(section: $this->alfaEntity, action: 'edit', user: $user)
            && !AlfaHelper::can(section: $this->alfaEntity, action: 'create', user: $user)
        ) {
            $this->setError(Text::_('JERROR_ALERTNOAUTHOR'));

            return false;
        }

        return parent::checkin($pks);
    }

    /**
     * Move records up or down: a state change, refused before Joomla checks the row out.
     *
     * AdminModel::reorder() checks a row out first and asks canEditState() after; on refusal it
     * checks the row back in, which {@see self::checkin()} refuses a user without edit; the row
     * would stay locked. Asking first leaves nothing to undo.
     *
     * @param mixed $pks The primary key or keys to move.
     * @param int $delta Increment, usually +1 or -1.
     *
     * @return bool|null
     *
     * @since  2.0.0
     */
    public function reorder($pks, $delta = 0)
    {
        if (!AlfaHelper::can(section: $this->alfaEntity, action: 'edit.state', user: $this->getCurrentUser())) {
            $this->setError(Text::_('JLIB_APPLICATION_ERROR_EDITSTATE_NOT_PERMITTED'));

            return false;
        }

        return parent::reorder($pks, $delta);
    }

    /**
     * Let plugins add fields and tabs to the edit form, and fill them.
     *
     * Runs for the entity's own edit form only: a model may load other forms through the same
     * funnel, and they are not what this contract is about.
     *
     * @param Form $form The form to preprocess.
     * @param mixed $data The data expected for the form.
     * @param string $group The name of the plugin group to import.
     *
     * @return void
     *
     * @since  2.0.0
     */
    protected function preprocessForm(Form $form, $data, $group = 'content')
    {
        parent::preprocessForm($form, $data, $group);

        if ($form->getName() !== 'com_alfa.' . $this->alfaEntity) {
            return;
        }

        $record = \is_object($data) ? $data : null;
        $values = \is_object($data) ? get_object_vars($data) : (array) $data;
        $isNew = empty($values['id']);

        // Changing a record's state is a permission of its own. Without it the state is shown
        // read-only and a posted value is dropped by the form filter before anything is saved;
        // the edit screen and the API validate through this same form.
        if ($form->getField('state') && !$this->canEditState($record ?? (object) $values)) {
            $form->setFieldAttribute('state', 'disabled', 'true');
            $form->setFieldAttribute('state', 'filter', 'unset');
        }

        // SCHEMA FIRST. A field must exist before a value can be bound to it, and loadForm() binds
        // immediately after this method returns.
        if ($this->alfaDispatches(AdminFormEvent::NAME)) {
            AlfaAdminDispatcher::dispatch(new AdminFormEvent(new AlfaAdminContext(
                entity: $this->alfaEntity,
                view: $this->alfaView(),
                screen: AlfaAdminContext::SCREEN_EDIT,
                phase: AlfaAdminContext::PHASE_FORM,
                user: $this->alfaUser(),
                token: $this->alfaToken(),
                form: $form,
                item: $record,
                isNew: $isNew,
                data: $values,
            )));
        }

        // LAST, so a translatable field a contributor just added through the form event gets its
        // per-language storage too, and before the early return below, which is where a stock
        // install with no prepare-data listener stops reading this method.
        MultilingualHelper::declareStorageFields(form: $form);

        if (!$this->alfaDispatches(AdminPrepareDataEvent::NAME)) {
            return;
        }

        $context = new AlfaAdminContext(
            entity: $this->alfaEntity,
            view: $this->alfaView(),
            screen: AlfaAdminContext::SCREEN_EDIT,
            phase: AlfaAdminContext::PHASE_PREPARE_DATA,
            user: $this->alfaUser(),
            token: $this->alfaToken(),
            item: $record,
            isNew: $isNew,
            data: $values,
        );

        AlfaAdminDispatcher::dispatch(new AdminPrepareDataEvent($context));

        // Held, not bound here: loadForm() binds the component's own data right after this
        // returns, so a contributor's value has to go on AFTER that or it would be overwritten by
        // the very data it meant to change.
        $this->alfaPreparedData = $context->data === $values ? null : $context->data;
    }

    /**
     * Bind whatever the prepare-data phase changed, after the component's own data is in.
     *
     * @param string $name The name of the form.
     * @param string|null $source The form source.
     * @param array $options Optional array of options for the form creation.
     * @param bool $clear Optional argument to force load a new form.
     * @param string|null $xpath An optional xpath to search for the fields.
     *
     * @return Form
     *
     * @since  2.0.0
     */
    protected function loadForm($name, $source = null, $options = [], $clear = false, $xpath = null)
    {
        $this->alfaPreparedData = null;

        $form = parent::loadForm($name, $source, $options, $clear, $xpath);

        if ($this->alfaPreparedData !== null) {
            $form->bind($this->alfaPreparedData);

            $this->alfaPreparedData = null;
        }

        return $form;
    }

    /**
     * Validate submitted data, then let plugins refuse it.
     *
     * Plugins are asked only once the component's own rules have passed: there is nothing useful
     * to say about data that is already invalid.
     *
     * @param Form $form The form to validate against.
     * @param array $data The data to validate.
     * @param string $group The name of the field group to validate.
     *
     * @return array|bool The filtered data on success, false when the component or a plugin refused.
     *
     * @since  2.0.0
     */
    public function validate($form, $data, $group = null)
    {
        $validated = parent::validate($form, $data, $group);

        if (!\is_array($validated) || !$this->alfaDispatches(AdminValidateEvent::NAME)) {
            return $validated;
        }

        $context = new AlfaAdminContext(
            entity: $this->alfaEntity,
            view: $this->alfaView(),
            screen: AlfaAdminContext::SCREEN_SAVE,
            phase: AlfaAdminContext::PHASE_VALIDATE,
            user: $this->alfaUser(),
            token: $this->alfaToken(),
            form: $form instanceof Form ? $form : null,
            item: $this->alfaLoadRecord($this->alfaRecordId($validated)),
            isNew: $this->alfaRecordId($validated) <= 0,
            data: $validated,
        );

        try {
            AlfaAdminDispatcher::dispatch(new AdminValidateEvent($context));
        } catch (Throwable $e) {
            // An abortable phase rethrows a handler's exception; a refused save must still look
            // like a refused save to the controller, not like an error page.
            $this->setError($e->getMessage());

            return false;
        }

        if (!$context->hasErrors()) {
            return $validated;
        }

        foreach ($context->errors as $error) {
            $this->setError($error);
        }

        return false;
    }

    /**
     * Save a record, letting plugins adjust it first and attach to it after.
     *
     * @param array $data The form data.
     *
     * @return bool True on success.
     *
     * @since  2.0.0
     */
    public function save($data)
    {
        $isNew = $this->alfaIsNew($data);

        if ($this->alfaDispatches(AdminBeforeSaveEvent::NAME)) {
            $context = new AlfaAdminContext(
                entity: $this->alfaEntity,
                view: $this->alfaView(),
                screen: AlfaAdminContext::SCREEN_SAVE,
                phase: AlfaAdminContext::PHASE_BEFORE_SAVE,
                user: $this->alfaUser(),
                token: $this->alfaToken(),
                item: $isNew ? null : $this->alfaLoadRecord($this->alfaRecordId($data)),
                isNew: $isNew,
                data: (array) $data,
            );

            try {
                AlfaAdminDispatcher::dispatch(new AdminBeforeSaveEvent($context));
            } catch (Throwable $e) {
                $this->setError($e->getMessage());

                return false;
            }

            $data = $context->data;
        }

        $result = parent::save($data);

        if ($result) {
            SetupHelper::invalidateFor($this->alfaEntity);
            CardRegistry::invalidateFor($this->alfaEntity);
        }

        if (!$result || !$this->alfaDispatches(AdminAfterSaveEvent::NAME)) {
            return $result;
        }

        $id = (int) $this->getState($this->getName() . '.id');

        AlfaAdminDispatcher::dispatch(new AdminAfterSaveEvent(new AlfaAdminContext(
            entity: $this->alfaEntity,
            view: $this->alfaView(),
            screen: AlfaAdminContext::SCREEN_SAVE,
            phase: AlfaAdminContext::PHASE_AFTER_SAVE,
            user: $this->alfaUser(),
            token: $this->alfaToken(),
            item: $this->alfaLoadRecord($id),
            ids: $id > 0 ? [$id] : [],
            isNew: $isNew,
            data: (array) $data,
        )));

        return $result;
    }

    /**
     * Delete records, letting plugins refuse first and clean up after.
     *
     * @param array $pks The primary keys to delete, by reference; the component narrows this list
     *                   as it goes, so what the after phase sees is what actually went.
     *
     * @return bool True on success.
     *
     * @since  2.0.0
     */
    public function delete(&$pks)
    {
        // Permission first: a refused delete must not reach the contributors' before-delete
        // handlers, which may do work of their own. The per-record check in parent::delete()
        // still runs, with each model's business rules.
        if (!AlfaHelper::can(section: $this->alfaEntity, action: 'delete', user: $this->getCurrentUser())) {
            $this->setError(Text::_('JLIB_APPLICATION_ERROR_DELETE_NOT_PERMITTED'));
            $pks = [];

            return false;
        }

        if ($this->alfaDispatches(AdminBeforeDeleteEvent::NAME)) {
            $context = new AlfaAdminContext(
                entity: $this->alfaEntity,
                view: $this->alfaView(),
                screen: AlfaAdminContext::SCREEN_DELETE,
                phase: AlfaAdminContext::PHASE_BEFORE_DELETE,
                user: $this->alfaUser(),
                token: $this->alfaToken(),
                ids: array_map('intval', (array) $pks),
            );

            try {
                AlfaAdminDispatcher::dispatch(new AdminBeforeDeleteEvent($context));
            } catch (Throwable $e) {
                $this->setError($e->getMessage());

                return false;
            }

            if ($context->hasErrors()) {
                foreach ($context->errors as $error) {
                    $this->setError($error);
                }

                return false;
            }
        }

        $result = parent::delete($pks);

        if ($result) {
            SetupHelper::invalidateFor($this->alfaEntity);
            CardRegistry::invalidateFor($this->alfaEntity);
        }

        if (!$result || !$this->alfaDispatches(AdminAfterDeleteEvent::NAME)) {
            return $result;
        }

        AlfaAdminDispatcher::dispatch(new AdminAfterDeleteEvent(new AlfaAdminContext(
            entity: $this->alfaEntity,
            view: $this->alfaView(),
            screen: AlfaAdminContext::SCREEN_DELETE,
            phase: AlfaAdminContext::PHASE_AFTER_DELETE,
            user: $this->alfaUser(),
            token: $this->alfaToken(),
            ids: array_map('intval', (array) $pks),
        )));

        return $result;
    }

    /**
     * Publish/unpublish/archive/trash from the list screen, keeping the setup
     * checklist honest: a state toggle changes what the detectors see exactly the
     * way a save does, but never passes through save().
     *
     * @param array &$pks The primary keys to change.
     * @param int $value The new state value.
     *
     * @return bool True on success.
     *
     * @since  2.0.0
     */
    public function publish(&$pks, $value = 1)
    {
        $result = parent::publish($pks, $value);

        if ($result) {
            SetupHelper::invalidateFor($this->alfaEntity);
            CardRegistry::invalidateFor($this->alfaEntity);
        }

        return $result;
    }

    /**
     * Whether this save creates a record.
     *
     * @param array $data The form data.
     *
     *
     * @since  2.0.0
     */
    private function alfaIsNew($data): bool
    {
        return $this->alfaRecordId($data) <= 0;
    }

    /**
     * The record this request is about, read the same way the component reads it.
     *
     * BOTH SOURCES ARE NEEDED. The submitted key is the obvious one, but a form can drop or blank
     * it (a readonly id field, a filter, a form that simply has no id field) and then the only
     * remaining truth is the edit state the controller set. AdminModel::save() consults exactly
     * these two in this order; trusting the payload alone reports every edit as a creation, which
     * is how `isNew` was wrong and the pre-image never loaded.
     *
     * @param array|object $data The form data.
     *
     * @return int The record id, or 0 for a record that does not exist yet.
     *
     * @since  2.0.0
     */
    private function alfaRecordId($data): int
    {
        try {
            $key = $this->getTable()->getKeyName();
        } catch (Throwable) {
            $key = 'id';
        }

        $pk = (int) (((array) $data)[$key] ?? 0);

        return $pk > 0 ? $pk : (int) $this->getState($this->getName() . '.id');
    }

    /**
     * The record as it is STORED right now: the pre-image for validate and before-save (so a
     * handler can compare what was submitted against what is there), and the post-image for
     * after-save (the record as it now is, not as it was submitted).
     *
     * A fresh Table each call, never the model's own cached instance, so a handler cannot disturb
     * the save in progress by writing to it. Loaded only when a contributor is listening.
     *
     * @param int $id The record id (0 for a record that does not exist yet).
     *
     * @return Table|null Null when there is no such record.
     *
     * @since  2.0.0
     */
    private function alfaLoadRecord(int $id): ?Table
    {
        if ($id <= 0) {
            return null;
        }

        try {
            $table = $this->getTable();

            return $table->load($id) ? $table : null;
        } catch (Throwable) {
            return null;
        }
    }
}
