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

/**
 * onAlfaAdminBeforeSave: the record data, after validation passed and before it is written.
 *
 * READ    entity, view, screen (save), user, item (the STORED record, null when new), isNew
 * WRITE   data: the record data, by handle
 *
 * The last point at which the record can still be changed:
 *
 *     public function onAlfaAdminBeforeSave(AdminBeforeSaveEvent $event): void
 *     {
 *         if (!$event->targets('product')) {
 *             return;
 *         }
 *
 *         $context = $event->getAdminContext();
 *         $context->data['sku'] = strtoupper(trim((string) ($context->data['sku'] ?? '')));
 *     }
 *
 * REACHED ONLY IF NOBODY VETOED: onAlfaAdminValidate has already run and produced no errors,
 * so this phase adjusts, it does not object. A handler that needs to refuse belongs in
 * validate, where refusing is free; refusing here means the user's save half-happened.
 *
 * The plugin's OWN tables are written in onAlfaAdminAfterSave, once the record has an id; a
 * new record has none yet at this point.
 *
 * @since  2.0.0
 */
final class AdminBeforeSaveEvent extends AlfaAdminEvent
{
    /**
     * @var string
     * @since  2.0.0
     */
    public const NAME = 'onAlfaAdminBeforeSave';
}
