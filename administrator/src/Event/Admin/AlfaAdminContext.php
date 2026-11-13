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

use Alfa\Component\Alfa\Administrator\Plugin\PluginId;
use InvalidArgumentException;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseQuery;
use Joomla\Registry\Registry;
use LogicException;

/**
 * WHERE a plugin is, WHAT it may read, and the ONE thing it may write: the single argument
 * every onAlfaAdmin* event carries.
 *
 * Raised on the shared dispatcher once the `alfa` PLATFORM group (the layer that owns SHARED
 * backend surfaces) is imported, so every listener already loaded hears it, a plugin of another
 * group included (2.2.0). Capability plugins (alfa-payments, alfa-shipments, ...) own their own
 * domain screens and layouts and are not imported for these: one that wants to reach into a
 * screen it does not own ships a second plugin in the `alfa` group. Entity names are the
 * component's own (`item` for a product).
 *
 * The admin contract puts the PHASE in the event NAME (finite, IDE-discoverable, cheap to
 * dispatch) and everything else in this object, so one plugin body can serve any entity:
 *
 *     public function onAlfaAdminListColumns(AdminListColumnsEvent $event): void
 *     {
 *         $context = $event->getAdminContext();
 *
 *         if (!$context->isListScreen() || !$context->targets('order')) {
 *             return;
 *         }
 *
 *         $context->columns->column('alfa.fraud', 'risk')->title('PLG_FRAUD_RISK')->after('status')
 *             ->setLayout('column_risk')
 *             ->setData(fn (object $row): ?array => $context->rowValues((int) $row->id));
 *     }
 *
 * THREE BANDS OF FIELDS.
 *
 *  - IDENTITY + ACTOR are readonly and always present: which entity/view/screen/phase is
 *    running, who is asking. {@see self::targets()} is the one-line guard built on them.
 *  - PAYLOAD is what the phase hands over. Object payloads (query, form, toolbar, item) are
 *    handles: mutating them mutates the real thing, which is how a plugin filters a list or
 *    adds a form tab. Which ones are non-null is a property of the PHASE, and each event
 *    class documents its own; reading one the phase does not set gives null, never a
 *    stale value from another phase.
 *  - RESULTS are the only things a plugin writes, and a phase permits exactly ONE. The rest
 *    are null (accumulator objects) or stay empty; writing to the wrong one is inert at
 *    worst and a TypeError at best, never a silent cross-phase side effect. The row values
 *    and the slot's markup are private: value() and append() are their only writers, each
 *    naming the contributing plugin, and rowData(), rowValues() and html() read them.
 *
 * The framework builds every context; a plugin only ever receives one. It is deliberately
 * inert (no Input, no database, no plugin boot), so a handler can read it and derive URLs
 * from it, but can never trigger a side effect through it. Same discipline as
 * {@see \Alfa\Component\Alfa\Administrator\Panel\PanelIdentity}.
 *
 * Handing ONE argument is also what keeps the family clear of Joomla's AbstractEvent
 * set-magic: no event in the family declares a set<Argument>() method, so nothing intercepts
 * the constructor's setArgument() pass.
 *
 * @since  2.0.0
 */
final class AlfaAdminContext
{
    /**
     * A list screen (the grid).
     *
     * @var string
     * @since  2.0.0
     */
    public const SCREEN_LIST = 'list';

    /**
     * An edit screen (the record form).
     *
     * @var string
     * @since  2.0.0
     */
    public const SCREEN_EDIT = 'edit';

    /**
     * A save round trip (validate -> before -> after).
     *
     * @var string
     * @since  2.0.0
     */
    public const SCREEN_SAVE = 'save';

    /**
     * A delete round trip (before -> after).
     *
     * @var string
     * @since  2.0.0
     */
    public const SCREEN_DELETE = 'delete';

    /**
     * The list query is being built.
     *
     * @var string
     * @since  2.0.0
     */
    public const PHASE_LIST_QUERY = 'listquery';

    /**
     * The list filter form is being prepared.
     *
     * @var string
     * @since  2.0.0
     */
    public const PHASE_LIST_FILTERS = 'listfilters';

    /**
     * List columns are being collected.
     *
     * @var string
     * @since  2.0.0
     */
    public const PHASE_LIST_COLUMNS = 'listcolumns';

    /**
     * Per-row data for the current page is being collected.
     *
     * @var string
     * @since  2.0.0
     */
    public const PHASE_LIST_ROW_DATA = 'listrowdata';

    /**
     * Per-row actions are being collected.
     *
     * @var string
     * @since  2.0.0
     */
    public const PHASE_LIST_ROW_ACTIONS = 'listrowactions';

    /**
     * Bulk (checked-rows) actions are being collected.
     *
     * @var string
     * @since  2.0.0
     */
    public const PHASE_LIST_BULK_ACTIONS = 'listbulkactions';

    /**
     * The screen's toolbar is being built.
     *
     * @var string
     * @since  2.0.0
     */
    public const PHASE_TOOLBAR = 'toolbar';

    /**
     * The edit form's schema is being prepared.
     *
     * @var string
     * @since  2.0.0
     */
    public const PHASE_FORM = 'form';

    /**
     * The edit form's data is being prepared, before it is bound.
     *
     * @var string
     * @since  2.0.0
     */
    public const PHASE_PREPARE_DATA = 'preparedata';

    /**
     * Submitted data is being validated (the veto point of a save).
     *
     * @var string
     * @since  2.0.0
     */
    public const PHASE_VALIDATE = 'validate';

    /**
     * A record is about to be written.
     *
     * @var string
     * @since  2.0.0
     */
    public const PHASE_BEFORE_SAVE = 'beforesave';

    /**
     * A record has been written.
     *
     * @var string
     * @since  2.0.0
     */
    public const PHASE_AFTER_SAVE = 'aftersave';

    /**
     * Records are about to be deleted (the veto point of a delete).
     *
     * @var string
     * @since  2.0.0
     */
    public const PHASE_BEFORE_DELETE = 'beforedelete';

    /**
     * Records have been deleted.
     *
     * @var string
     * @since  2.0.0
     */
    public const PHASE_AFTER_DELETE = 'afterdelete';

    /**
     * A named render slot on the screen is being filled.
     *
     * @var string
     * @since  2.0.0
     */
    public const PHASE_RENDER = 'render';

    /**
     * The phases that change stored state. They are reachable only through a controller
     * task that has already validated the CSRF token (Joomla's native checkToken()), which
     * is the whole read/write boundary, the same one the Panel framework relies on. A
     * handler that finds {@see self::isMutating()} false must not write.
     *
     * @var string[]
     * @since  2.0.0
     */
    private const MUTATING_PHASES = [
        self::PHASE_VALIDATE,
        self::PHASE_BEFORE_SAVE,
        self::PHASE_AFTER_SAVE,
        self::PHASE_BEFORE_DELETE,
        self::PHASE_AFTER_DELETE,
    ];

    /**
     * @param string $entity Entity machine name, singular ('order', 'manufacturer').
     * @param string $view The raw Joomla view being served ('orders', 'order').
     * @param string $screen One of the SCREEN_* constants.
     * @param string $phase One of the PHASE_* constants (matches the event name).
     * @param User $user The acting user.
     * @param string $token The session's CSRF form-token name, so a contributed
     *                      action or layout can post back safely.
     * @param Registry|null $state The model state (list screens: filters, ordering, limits).
     * @param string|null $layout The active layout override in the item->category->menu->global
     *                            chain, when the screen resolved one.
     * @param string|null $slot The render position being filled (PHASE_RENDER only).
     * @param DatabaseQuery|null $query The list query, live (PHASE_LIST_QUERY).
     * @param Form|null $form The edit form (PHASE_FORM/VALIDATE) or the filter form
     *                        (PHASE_LIST_FILTERS), live.
     * @param Toolbar|null $toolbar The screen's toolbar, live (PHASE_TOOLBAR).
     * @param object|null $item The single record in play (edit/save/delete screens).
     * @param array|null $items The current page's rows (list screens, after enrichment).
     * @param int[]|null $ids The primary keys in play (the page's rows, or the
     *                        records being deleted).
     * @param bool|null $isNew Whether a save creates a record (save screens).
     * @param AdminColumns|null $columns RESULT: contributed list columns (PHASE_LIST_COLUMNS).
     * @param AdminActions|null $actions RESULT: contributed row actions (PHASE_LIST_ROW_ACTIONS).
     * @param AdminActions|null $bulkActions RESULT: contributed bulk actions (PHASE_LIST_BULK_ACTIONS).
     * @param array $data PAYLOAD+RESULT: the record data, mutable in
     *                    PHASE_PREPARE_DATA and PHASE_BEFORE_SAVE.
     * @param array $rowData RESULT: [id => [key => value]] for the whole page
     *                       (PHASE_LIST_ROW_DATA), written only through value() and read
     *                       through rowData() and rowValues() by column resolvers later.
     * @param string[] $errors RESULT: a non-empty list vetoes the operation
     *                         (PHASE_VALIDATE, PHASE_BEFORE_DELETE).
     * @param string $html RESULT: markup for the slot (PHASE_RENDER), written only through
     *                     append() and read through html().
     *
     * @since  2.0.0
     */
    public function __construct(
        public readonly string $entity,
        public readonly string $view,
        public readonly string $screen,
        public readonly string $phase,
        public readonly User $user,
        public readonly string $token = '',
        public readonly ?Registry $state = null,
        public readonly ?string $layout = null,
        public readonly ?string $slot = null,
        public readonly ?DatabaseQuery $query = null,
        public readonly ?Form $form = null,
        public readonly ?Toolbar $toolbar = null,
        public readonly ?object $item = null,
        public readonly ?array $items = null,
        public readonly ?array $ids = null,
        public readonly ?bool $isNew = null,
        public readonly ?AdminColumns $columns = null,
        public readonly ?AdminActions $actions = null,
        public readonly ?AdminActions $bulkActions = null,
        public array $data = [],
        private array $rowData = [],
        public array $errors = [],
        private string $html = '',
    ) {
    }

    /**
     * Which plugin wrote each row value key (2.2.0): a key belongs to the first plugin that writes it.
     *
     * @var array<string, string>
     * @since  2.2.0
     */
    private array $rowDataOwners = [];

    /**
     * Contribute one value key for the page's rows (2.2.0, PHASE_LIST_ROW_DATA): `$values` holds the value per row id
     * and lands as [id => [key => value]], where the plugin's columns read it. A key belongs to the first plugin that
     * writes it; another plugin writing the same key is refused with a log line. The only way a value enters.
     *
     * @param string $contributor The contributing plugin, group.element (attribution, never an authorisation).
     * @param string $key The value key, read back by the plugin's column.
     * @param array<int, mixed> $values The value per row id.
     *
     * @throws InvalidArgumentException When the contributor is not group.element.
     *
     * @since  2.2.0
     */
    public function value(string $contributor, string $key, array $values): void
    {
        $owner = PluginId::of($contributor)->key();

        if (($this->rowDataOwners[$key] ?? $owner) !== $owner) {
            Log::add('Alfa admin events: ' . AdminListRowDataEvent::NAME . ': ' . $owner . ' wrote the value key ' . $key . ', which ' . $this->rowDataOwners[$key] . ' already wrote; refused.', Log::WARNING, 'com_alfa.admin');

            return;
        }

        $this->rowDataOwners[$key] = $owner;

        foreach ($values as $id => $value) {
            $this->rowData[(int) $id][$key] = $value;
        }
    }

    /**
     * The page's row values: [id => [key => value]] (2.2.0).
     *
     * @return array<int, array<string, mixed>>
     *
     * @since  2.2.0
     */
    public function rowData(): array
    {
        return $this->rowData;
    }

    /**
     * One row's values, keyed by value key; null when no plugin wrote any for it (2.2.0).
     *
     * @param int $id The row id.
     *
     * @return array<string, mixed>|null
     *
     * @since  2.2.0
     */
    public function rowValues(int $id): ?array
    {
        return $this->rowData[$id] ?? null;
    }

    /**
     * The markup appended for the slot (2.2.0).
     *
     * @since  2.2.0
     */
    public function html(): string
    {
        return $this->html;
    }

    /**
     * The ENTITY half of the guard: does this context concern one of these entities? Variadic,
     * so a handler serving several grids names them all in one call. The SCREEN half is the
     * is*Screen() predicates: a closed set of four, so the screen can never be mistyped and no
     * guard silently means "both screens".
     *
     *     if (!$context->targets('order')) return;                       // one entity, any screen
     *     if (!$context->isListScreen()) return;                         // any entity's grid
     *     if (!$context->isListScreen()
     *         || !$context->targets('currency', 'place')) return;        // a chosen set of grids
     *
     * A handler that guards NOTHING is entity-agnostic on purpose (that is the contract's
     * headline, one body for every entity), so simply do not call targets() for that.
     *
     * GUARD-RAIL: the old two-argument form targets('order', 'list') would now silently mean
     * "entity order OR entity list" and never match a screen again, so passing a screen name
     * here throws immediately, naming the predicate to use instead. Loud beats silent.
     *
     * @param string $entity An entity machine name to match.
     * @param string ...$more Further entity names; matching ANY of them passes.
     *
     * @return bool Whether this context concerns one of the named entities.
     *
     * @throws LogicException When a screen name is passed where an entity belongs.
     *
     * @since  2.0.0
     */
    public function targets(string $entity, string ...$more): bool
    {
        $candidates = [$entity, ...$more];

        // Validate EVERY argument before matching any: targets('currency', 'list') must throw,
        // not quietly succeed because 'currency' matched first and 'list' was never looked at.
        foreach ($candidates as $candidate) {
            if (\in_array($candidate, [self::SCREEN_LIST, self::SCREEN_EDIT, self::SCREEN_SAVE, self::SCREEN_DELETE], true)) {
                throw new LogicException(sprintf(
                    "targets() takes entity names; '%s' is a screen. Guard it with is%sScreen().",
                    $candidate,
                    ucfirst($candidate),
                ));
            }
        }

        return \in_array($this->entity, $candidates, true);
    }

    /**
     * Whether this is a LIST screen (the grid). One of four screen predicates: a closed,
     * typo-proof set, deliberately with no variant that matches more than one screen.
     *
     *
     * @since  2.0.0
     */
    public function isListScreen(): bool
    {
        return $this->screen === self::SCREEN_LIST;
    }

    /**
     * Whether this is an EDIT screen (the record form).
     *
     *
     * @since  2.0.0
     */
    public function isEditScreen(): bool
    {
        return $this->screen === self::SCREEN_EDIT;
    }

    /**
     * Whether this is a SAVE round trip (validate -> before -> after).
     *
     *
     * @since  2.0.0
     */
    public function isSaveScreen(): bool
    {
        return $this->screen === self::SCREEN_SAVE;
    }

    /**
     * Whether this is a DELETE round trip (before -> after).
     *
     *
     * @since  2.0.0
     */
    public function isDeleteScreen(): bool
    {
        return $this->screen === self::SCREEN_DELETE;
    }

    /**
     * Whether the render slot being filled is this one: the guard for PHASE_RENDER, where
     * one handler typically serves several positions on the same screen.
     *
     * @param string $slot The slot name.
     *
     *
     * @since  2.0.0
     */
    public function at(string $slot): bool
    {
        return $this->slot === $slot;
    }

    /**
     * Whether the running phase is any of the given ones. Useful for a handler subscribed to
     * several events through one shared body.
     *
     * @param string ...$phases PHASE_* constants.
     *
     *
     * @since  2.0.0
     */
    public function is(string ...$phases): bool
    {
        return \in_array($this->phase, $phases, true);
    }

    /**
     * Whether this phase is allowed to change stored state. Derived from the phase, not from
     * the request: the mutating phases are reachable only through a CSRF-checked controller
     * task, so a handler that writes when this is false is writing on a GET.
     *
     *
     * @since  2.0.0
     */
    public function isMutating(): bool
    {
        return \in_array($this->phase, self::MUTATING_PHASES, true);
    }

    /**
     * The canonical content-context string for this ENTITY: screen-less, so it is stable across
     * every screen of the entity, which is what makes it the right STORAGE key ('where do I keep
     * my rows for this entity'). The event's {@see AlfaAdminEvent::getContext()} is the
     * screen-carrying Joomla-shaped string ('com_alfa.currency.list') for guards and logs.
     *
     * @return string e.g. 'com_alfa.order'.
     *
     * @since  2.0.0
     */
    public function contentContext(): string
    {
        return 'com_alfa.' . $this->entity;
    }

    /**
     * Composite identity: entity.screen.phase. Used in log lines so a misbehaving handler is
     * traceable to the exact dispatch.
     *
     *
     * @since  2.0.0
     */
    public function key(): string
    {
        return $this->entity . '.' . $this->screen . '.' . $this->phase;
    }

    /**
     * Veto the operation with a reason. Veto is DATA, never an exception: the model collects
     * every handler's objection, aborts cleanly and redisplays with all of them shown, instead
     * of dying on the first one.
     *
     * @param string $message The reason, already translated.
     *
     *
     * @since  2.0.0
     */
    public function addError(string $message): void
    {
        $this->errors[] = $message;
    }

    /**
     * Whether any handler vetoed.
     *
     *
     * @since  2.0.0
     */
    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * Append markup to the slot being rendered: the only way markup enters (2.2.0).
     *
     * @param string $contributor The contributing plugin, group.element (2.2.0; attribution, never an authorisation).
     * @param string $html The markup.
     *
     * @throws InvalidArgumentException When the contributor is not group.element.
     *
     * @since  2.0.0
     */
    public function append(string $contributor, string $html): void
    {
        PluginId::of($contributor);
        $this->html .= $html;
    }
}
