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
 * onAlfaAdminForm: the edit form's SCHEMA, after the component loaded it.
 *
 * READ    entity, view, screen (edit), user, item, isNew, data
 * WRITE   form: the live Form
 *
 * A field added here appears on the screen; a whole <fieldset> added here becomes a TAB, which
 * is how a plugin gets its own section on an entity it does not own:
 *
 *     public function onAlfaAdminForm(AdminFormEvent $event): void
 *     {
 *         if (!$event->isEditScreen() || !$event->targets('product')) {
 *             return;
 *         }
 *
 *         $event->getAdminContext()->form->load(
 *             '<form><fields name="params">'
 *             . '<fieldset name="warranty" label="PLG_ALFA_WARRANTY">'
 *             . '<field name="months" type="number" label="PLG_ALFA_WARRANTY_MONTHS" default="24" />'
 *             . '</fieldset></fields></form>'
 *         );
 *     }
 *
 * SCHEMA ONLY. Values belong to onAlfaAdminPrepareData, which fires with the record's data
 * before it is bound; setting a value here fights the bind that follows.
 *
 * Store contributed values under a fields group the entity already persists (params, or the
 * plugin's own table written from onAlfaAdminAfterSave). A bare top-level field whose column
 * does not exist is dropped on save without a word.
 *
 * @since  1.8.0
 */
final class AdminFormEvent extends AlfaAdminEvent
{
    /**
     * @var string
     * @since  1.8.0
     */
    public const NAME = 'onAlfaAdminForm';
}
