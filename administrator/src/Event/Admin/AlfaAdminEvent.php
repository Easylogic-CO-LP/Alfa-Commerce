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

use Joomla\CMS\Event\AbstractEvent;
use LogicException;

/**
 * The shape shared by every onAlfaAdmin* event: a fixed name and exactly ONE argument, the
 * {@see AlfaAdminContext}.
 *
 * Why an event class per phase at all, when they carry the same payload type? Because the
 * PHASE is the part a plugin subscribes to, and putting it in the name makes the family
 * finite, discoverable in an IDE, and free to dispatch, while the entity, screen and slot
 * stay in the context so ONE handler body can serve any of the ~25 admin entities:
 *
 *     public function onAlfaAdminListColumns(AdminListColumnsEvent $event): void
 *     {
 *         if (!$event->isListScreen() || !$event->targets('order')) {
 *             return;
 *         }
 *
 *         $event->getAdminContext()->columns->column('risk')
 *             ->title('PLG_FRAUD_RISK')
 *             ->setLayout('column_risk');
 *     }
 *
 * Each concrete event documents which context fields its phase makes live and which single
 * accumulator it may write. A payload field another phase owns reads back as null (never a stale
 * value from a different phase), and an accumulator another phase owns is either null (columns,
 * actions) or simply empty (data, rowData, errors, html), so writing to the wrong one is inert
 * rather than damaging.
 *
 * ONE ARGUMENT IS ALSO A SAFETY PROPERTY. Joomla's AbstractEvent runs every constructor
 * argument through setArgument(), which silently hands the value to a matching set<Argument>()
 * method if one exists; a fluent setter would then store itself in place of the value. No
 * event in this family declares a setter of any kind: the context is passed once at
 * construction and mutated through the context object, never through the event.
 *
 * @since  1.8.0
 */
abstract class AlfaAdminEvent extends AbstractEvent
{
    /**
     * The event name: the exact method a plugin implements. Every concrete event overrides this.
     *
     * @var string
     * @since  1.8.0
     */
    public const NAME = '';

    /**
     * @param AlfaAdminContext $context Where the plugin is, what it may read, what it may write.
     *
     * @throws LogicException When a concrete event forgot to declare its NAME.
     *
     * @since  1.8.0
     */
    public function __construct(AlfaAdminContext $context)
    {
        if (static::NAME === '') {
            throw new LogicException(static::class . ' must declare a NAME.');
        }

        parent::__construct(static::NAME, ['context' => $context]);
    }

    /**
     * The rich context this event carries: the one argument of the whole family. Everything a
     * handler reads or writes lives on it.
     *
     * NAMING SYSTEM, so the family stays predictable: ACCESSORS are get-prefixed (this one and
     * {@see self::getContext()}); PREDICATES are is-prefixed or verbs (isListScreen(), targets(), at()).
     * The two context accessors carry visibly different names on purpose: a pair differing only
     * by a `get` prefix while returning different types would be a trap of its own.
     *
     *
     * @since  1.8.0
     */
    public function getAdminContext(): AlfaAdminContext
    {
        return $this->arguments['context'];
    }

    /**
     * The Joomla-shaped context STRING: 'com_alfa.<entity>.<screen>', e.g.
     * 'com_alfa.currency.list'.
     *
     * FAMILIAR NAMES ARE CONTRACTS. On every Joomla model event, getContext() returns a string,
     * and a developer's muscle memory writes `if ($event->getContext() !== '...') return;` without
     * looking it up. Returning an object here would make that guard silently never match (the
     * worst failure mode there is), so this method honours the Joomla meaning, and the rich
     * object lives on {@see self::getAdminContext()} instead.
     *
     * Guarding on this string WORKS but is typo-prone (a misspelt string never matches, silently).
     * Prefer the typo-proof guard: {@see self::targets()} for the entity and the is*Screen()
     * predicates for the screen.
     *
     *
     * @since  1.8.0
     */
    public function getContext(): string
    {
        $context = $this->getAdminContext();

        return 'com_alfa.' . $context->entity . '.' . $context->screen;
    }

    /**
     * The entity guard: {@see AlfaAdminContext::targets()} without unwrapping first.
     *
     * @param string $entity An entity machine name to match.
     * @param string ...$more Further entity names; matching ANY of them passes.
     *
     *
     * @since  1.8.0
     */
    public function targets(string $entity, string ...$more): bool
    {
        return $this->getAdminContext()->targets($entity, ...$more);
    }

    /**
     * The screen predicates: {@see AlfaAdminContext::isListScreen()} and siblings, proxied so a
     * guard stays one object. A closed set of four; none matches more than one screen.
     *
     *
     * @since  1.8.0
     */
    public function isListScreen(): bool
    {
        return $this->getAdminContext()->isListScreen();
    }

    /**
     * @since  1.8.0
     */
    public function isEditScreen(): bool
    {
        return $this->getAdminContext()->isEditScreen();
    }

    /**
     * @since  1.8.0
     */
    public function isSaveScreen(): bool
    {
        return $this->getAdminContext()->isSaveScreen();
    }

    /**
     * @since  1.8.0
     */
    public function isDeleteScreen(): bool
    {
        return $this->getAdminContext()->isDeleteScreen();
    }

    /**
     * The render-slot guard: {@see AlfaAdminContext::at()} without unwrapping first.
     *
     * @param string $slot The slot name.
     *
     *
     * @since  1.8.0
     */
    public function at(string $slot): bool
    {
        return $this->getAdminContext()->at($slot);
    }

    /**
     * The phase guard: {@see AlfaAdminContext::is()} without unwrapping first.
     *
     * @param string ...$phases PHASE_* constants.
     *
     *
     * @since  1.8.0
     */
    public function is(string ...$phases): bool
    {
        return $this->getAdminContext()->is(...$phases);
    }
}
