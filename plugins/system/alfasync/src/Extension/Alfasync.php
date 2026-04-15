<?php

/**
 * @package     Plg_System_Alfasync
 * @subpackage  System
 * @author      Agamemnon Fakas <info@easylogic.gr>
 * @copyright   (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE
 *
 * Central sync plugin for Alfa Commerce.
 *
 * This plugin is the RUNTIME counterpart of script.php's postflight sync.
 * Where script.php handles the bulk seed of all existing users/groups on
 * install/update, this plugin keeps the Alfa tables in sync for every
 * subsequent Joomla event (save, delete, …).
 *
 * All DB logic lives in SyncHelper — this file only handles:
 *   - Event subscription (getSubscribedEvents)
 *   - Argument extraction from Joomla events (typed + legacy)
 *   - Param guards (allow admins to toggle sync targets on/off)
 *   - Logging
 *
 * Extending
 * ---------
 * To listen to a new event:
 *   1. Add event → handler in getSubscribedEvents().
 *   2. Write the handler (extract args, call SyncHelper).
 *   3. Add any DB logic to SyncHelper if it doesn't exist yet.
 *
 * Custom events from com_alfa
 * ---------------------------
 * Dispatch from your component model:
 *
 *   $event = new \Joomla\Event\Event('onAlfaProductAfterSave', [
 *       'data'  => $productData,
 *       'isNew' => $isNew,
 *   ]);
 *   Factory::getApplication()->getDispatcher()->dispatch('onAlfaProductAfterSave', $event);
 *
 * Then add the handler here and the INSERT/UPDATE logic in SyncHelper.
 */

namespace Alfa\Plugin\System\Alfasync\Extension;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\SyncHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;

/**
 * Alfa Commerce — central sync system plugin.
 *
 * @since  1.0.0
 */
class Alfasync extends CMSPlugin implements SubscriberInterface
{
    /** @var bool */
    protected $autoloadLanguage = true;

    // =========================================================================
    // Event subscriptions — the only place to touch when adding a sync target
    // =========================================================================

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            // User events — always active
            'onUserAfterSave'      => 'onUserAfterSave',
            'onUserAfterSaveGroup' => 'onUserAfterSaveGroup',
            'onUserAfterDelete'    => 'onUserAfterDelete',

            // Content events — uncomment to activate
            // 'onContentAfterSave'   => 'onContentAfterSave',
            // 'onContentAfterDelete' => 'onContentAfterDelete',

            // Custom Alfa events — uncomment + dispatch from component/plugin
            // 'onAlfaProductAfterSave'  => 'onAlfaProductAfterSave',
            // 'onAlfaOrderAfterSave'    => 'onAlfaOrderAfterSave',
            // 'onAlfaCategoryAfterSave' => 'onAlfaCategoryAfterSave',
        ];
    }

    // =========================================================================
    // User event handlers
    // =========================================================================

    /**
     * Called after a Joomla user is saved (registration, profile edit, admin save).
     *
     * Available in $data:
     *   id, name, username, email, password, block, sendEmail,
     *   registerDate, lastvisitDate, activation, params, groups, …
     *
     * @param   Event|array  $event
     * @param   bool|null    $isNew
     * @param   bool|null    $success
     *
     * @return  void
     */
    public function onUserAfterSave($event, ?bool $isNew = null, ?bool $success = null): void
    {

        [$data, $isNew, $success] = $this->extractUserArgs($event, $isNew, $success);

        if (!$success || empty($data['id']))
        {
            return;
        }

        if (!(bool) $this->params->get('sync_users', 1))
        {
            return;
        }

        $userId = (int) $data['id'];

        try
        {
            $inserted = SyncHelper::insertAlfaUser($this->getDatabase(), $userId);
            $this->logDebug('onUserAfterSave', ($inserted ? 'Inserted' : 'Already exists') . ' — user #' . $userId);
        }
        catch (\Exception $e)
        {
            $this->logError('onUserAfterSave', $e->getMessage(), ['user_id' => $userId]);
        }
    }

    /**
     * Called after a Joomla usergroup is saved (Users → Groups).
     *
     * Available in $group: id, title, parent_id.
     *
     * @param   Event|array  $event
     * @param   bool|null    $isNew
     *
     * @return  void
     */
    public function onUserAfterSaveGroup($event, ?bool $isNew = null): void
    {

        [$group, $isNew] = $this->extractGroupArgs($event, $isNew);

        if (empty($group['id']))
        {
            return;
        }

        if (!(bool) $this->params->get('sync_usergroups', 1))
        {
            return;
        }

        $groupId = (int) $group['id'];

        try
        {
            // Pass null → SyncHelper reads prices_display defaults from
            // the seeded com_alfa component params via ComponentHelper.
            $inserted = SyncHelper::insertAlfaUsergroup($this->getDatabase(), $groupId);
            $this->logDebug('onUserAfterSaveGroup', ($inserted ? 'Inserted' : 'Already exists') . ' — group #' . $groupId);
        }
        catch (\Exception $e)
        {
            $this->logError('onUserAfterSaveGroup', $e->getMessage(), ['group_id' => $groupId]);
        }
    }

    /**
     * Called after a Joomla user is deleted.
     *
     * @param   Event|array  $event
     * @param   bool|null    $success
     *
     * @return  void
     */
    public function onUserAfterDelete($event, ?bool $success = null): void
    {
        if ($event instanceof Event)
        {
            $user    = (array) ($event->getArgument('subject') ?? $event->getArgument('0') ?? []);
            $success = (bool) ($event->getArgument('1') ?? $event->getArgument('success') ?? true);
        }
        else
        {
            $user    = (array) $event;
            $success = (bool) $success;
        }

        if (!$success || empty($user['id']))
        {
            return;
        }

        if (!(bool) $this->params->get('delete_users', 1))
        {
            return;
        }

        $userId = (int) $user['id'];

        try
        {
            $db    = $this->getDatabase();
            $query = $db->getQuery(true)
                ->delete($db->quoteName('#__alfa_users'))
                ->where($db->quoteName('id_user') . ' = ' . $userId);

            $db->setQuery($query)->execute();
            $this->logDebug('onUserAfterDelete', 'Deleted alfa user #' . $userId);
        }
        catch (\Exception $e)
        {
            $this->logError('onUserAfterDelete', $e->getMessage(), ['user_id' => $userId]);
        }
    }

    // =========================================================================
    // Content event handlers — uncomment in getSubscribedEvents() to activate
    // =========================================================================

    /**
     * Called after any Joomla content item is saved.
     *
     * Filter by $context to act only on the items you care about:
     *   'com_content.article'     – standard article
     *   'com_categories.category' – category
     *
     * @param   Event  $event
     *
     * @return  void
     */
    // public function onContentAfterSave(Event $event): void
    // {
    //     $context = $event->getArgument('0') ?? $event->getArgument('context') ?? '';
    //     $item    = $event->getArgument('1') ?? $event->getArgument('subject');
    //     $isNew   = (bool) ($event->getArgument('2') ?? false);
    //
    //     if ($context !== 'com_content.article') {
    //         return;
    //     }
    //
    //     // Add to SyncHelper and call it here.
    // }

    // =========================================================================
    // Custom Alfa event handlers — uncomment in getSubscribedEvents() to activate
    // =========================================================================

    // public function onAlfaProductAfterSave(Event $event): void
    // {
    //     $data  = $event->getArgument('data')  ?? [];
    //     $isNew = (bool) ($event->getArgument('isNew') ?? false);
    //
    //     if (empty($data['id'])) {
    //         return;
    //     }
    //
    //     // Add SyncHelper::insertAlfaProduct() and call it here.
    // }

    // =========================================================================
    // Argument extraction helpers
    // =========================================================================

    /**
     * Extracts [userData, isNew, success] from a typed Event or legacy array call.
     *
     * @return array{0: array, 1: bool, 2: bool}
     */
    private function extractUserArgs($event, ?bool $isNew, ?bool $success): array
    {
        if ($event instanceof Event)
        {
            $data    = (array) ($event->getArgument('subject') ?? $event->getArgument('0') ?? []);
            $isNew   = (bool) ($event->getArgument('1') ?? $event->getArgument('isNew')   ?? false);
            $success = (bool) ($event->getArgument('2') ?? $event->getArgument('success') ?? true);
        }
        else
        {
            $data    = (array) $event;
            $isNew   = (bool) $isNew;
            $success = (bool) $success;
        }

        return [$data, $isNew, $success];
    }

    /**
     * Extracts [groupData, isNew] from a typed Event or legacy array call.
     *
     * @return array{0: array, 1: bool}
     */
    private function extractGroupArgs($event, ?bool $isNew): array
    {
        if ($event instanceof Event)
        {
            $group = (array) ($event->getArgument('subject') ?? $event->getArgument('0') ?? []);
            $isNew = (bool) ($event->getArgument('1') ?? $event->getArgument('isNew') ?? false);
        }
        else
        {
            $group = (array) $event;
            $isNew = (bool) $isNew;
        }

        return [$group, $isNew];
    }

    // =========================================================================
    // Database helper
    // =========================================================================

    /**
     * Returns the DB driver. Prefers the injected $this->db (Joomla 4.2+).
     *
     * @return \Joomla\Database\DatabaseInterface
     */
    private function getDatabase()
    {
        if (isset($this->db) && $this->db !== null)
        {
            return $this->db;
        }

        return Factory::getContainer()->get('DatabaseDriver');
    }

    // =========================================================================
    // Logging
    // =========================================================================

    private function logError(string $caller, string $message, array $context = []): void
    {
        $this->writeLog(Log::ERROR, $caller, $message, $context);
    }

    private function logDebug(string $caller, string $message): void
    {
        if (!(bool) $this->params->get('debug_log', 0))
        {
            return;
        }

        $this->writeLog(Log::DEBUG, $caller, $message);
    }

    private function writeLog(int $level, string $caller, string $message, array $context = []): void
    {
        Log::addLogger(['text_file' => 'alfa_sync.php'], Log::ALL, ['alfa_sync']);

        $ctx  = empty($context) ? '' : ' | ' . json_encode($context);
        Log::add('[' . $caller . '] ' . $message . $ctx, $level, 'alfa_sync');
    }
}
