<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Notifications;

\defined('_JEXEC') or die;

use Joomla\CMS\Event\AbstractImmutableEvent;

/**
 * A backend notification was written: `onAlfaNotificationPushed`.
 *
 * Dispatched by {@see \Alfa\Component\Alfa\Administrator\Helper\NotificationHelper::push()} once per push, after
 * the row is written and with no database transaction open: a push made inside a transaction is announced after that
 * transaction's outermost commit, when its locks are released, and a push that a rollback discarded announces
 * nothing. It is the one notification-specific event: a notification is never saved through an admin record model, so
 * no generic save event fires for it.
 *
 * READ    id, dedupKey, group, severity, title, message, url, change (new, updated or re-alerted)
 * WRITE   nothing: the notification is written; this reports it
 *
 *     public function onAlfaNotificationPushed(NotificationPushedEvent $event): void
 *     {
 *         if ($event->severity() !== 'danger') {
 *             return;
 *         }
 *
 *         $this->relay($event->title(), $event->message());
 *     }
 *
 * A listener's failure is logged and never undoes or breaks the push; the other listeners still hear it. The values
 * are the row as written (the url already made safe), not as the producer passed them.
 *
 * The accessors carry no `get` prefix: Joomla's AbstractEvent::getArgument() calls a get<Name>() method of the event
 * as a value pre-processor.
 *
 * @since  2.1.1
 */
final class NotificationPushedEvent extends AbstractImmutableEvent
{
    /**
     * The event a plugin implements as a method of this name.
     *
     * @var string
     * @since  2.1.1
     */
    public const NAME = 'onAlfaNotificationPushed';

    /**
     * The push created the notification.
     *
     * @var string
     * @since  2.1.1
     */
    public const CHANGE_NEW = 'new';

    /**
     * The push updated a notification its key already named, keeping its read and dismissed state.
     *
     * @var string
     * @since  2.1.1
     */
    public const CHANGE_UPDATED = 'updated';

    /**
     * The push raised a notification its key already named again: unread and active once more.
     *
     * @var string
     * @since  2.1.1
     */
    public const CHANGE_REALERTED = 'realerted';

    /**
     * The notification row's id.
     *
     *
     * @since  2.1.1
     */
    public function id(): int
    {
        return (int) ($this->arguments['id'] ?? 0);
    }

    /**
     * The producer's key, which deduplicates the notification.
     *
     *
     * @since  2.1.1
     */
    public function dedupKey(): string
    {
        return (string) ($this->arguments['dedupKey'] ?? '');
    }

    /**
     * The group the notification is listed under: a group key (COM_ALFA_NOTIFY_GROUP_ORDERS) since 2.1.4, or literal
     * text a third party gave ({@see \Alfa\Component\Alfa\Administrator\Helper\NotificationText::group()} says it in
     * a reader's language).
     *
     *
     * @since  2.1.1
     */
    public function group(): string
    {
        return (string) ($this->arguments['group'] ?? '');
    }

    /**
     * The severity: success, info, warning or danger.
     *
     *
     * @since  2.1.1
     */
    public function severity(): string
    {
        return (string) ($this->arguments['severity'] ?? '');
    }

    /**
     * The headline as stored: since 2.1.4 the snapshot in the shop's default administrator language of
     * {@see self::titleKey()} and {@see self::arguments()}, or the literal text a third party gave.
     *
     *
     * @since  2.1.1
     */
    public function title(): string
    {
        return (string) ($this->arguments['title'] ?? '');
    }

    /**
     * The message as stored ('' when the notification has none): the snapshot of {@see self::messageKey()}, or literal
     * text.
     *
     *
     * @since  2.1.1
     */
    public function message(): string
    {
        return (string) ($this->arguments['message'] ?? '');
    }

    /**
     * The link as stored: relative, or absolute http or https; '' when there is none or it was not safe.
     *
     *
     * @since  2.1.1
     */
    public function url(): string
    {
        return (string) ($this->arguments['url'] ?? '');
    }

    /**
     * What the push did: {@see self::CHANGE_NEW}, {@see self::CHANGE_UPDATED} or {@see self::CHANGE_REALERTED}.
     *
     *
     * @since  2.1.1
     */
    public function change(): string
    {
        return (string) ($this->arguments['change'] ?? '');
    }

    /**
     * The language key of the headline; '' when the headline is literal text.
     *
     *
     * @since  2.1.4
     */
    public function titleKey(): string
    {
        return (string) ($this->arguments['titleKey'] ?? '');
    }

    /**
     * The language key of the message; '' when the message is literal text or there is none.
     *
     *
     * @since  2.1.4
     */
    public function messageKey(): string
    {
        return (string) ($this->arguments['messageKey'] ?? '');
    }

    /**
     * The keys' parameters: {title: [...], message: [...]}, as NotificationText keeps them; empty for literal text.
     *
     * @return array<string, list<mixed>>
     *
     * @since  2.1.4
     */
    public function arguments(): array
    {
        return (array) ($this->arguments['textArgs'] ?? []);
    }

    /**
     * The extension whose language files hold the keys, e.g. com_alfa; '' for literal text.
     *
     *
     * @since  2.1.4
     */
    public function textDomain(): string
    {
        return (string) ($this->arguments['textDomain'] ?? '');
    }
}
