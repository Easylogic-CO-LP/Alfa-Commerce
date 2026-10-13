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
 * subsequent Joomla event (save, delete, ...).
 *
 * All DB logic lives in SyncHelper; this file only handles:
 *   - Event subscription (getSubscribedEvents)
 *   - Argument extraction from Joomla events (typed + legacy)
 *   - Param guards (allow admins to toggle sync targets on/off)
 *   - Logging
 *
 * Extending
 * To listen to a new event:
 *   1. Add event -> handler in getSubscribedEvents().
 *   2. Write the handler (extract args, call SyncHelper).
 *   3. Add any DB logic to SyncHelper if it doesn't exist yet.
 *
 * Custom events from com_alfa
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

use Alfa\Component\Alfa\Administrator\Helper\FieldsHelper;
use Alfa\Component\Alfa\Administrator\Helper\SetupHelper;
use Alfa\Component\Alfa\Administrator\Helper\SyncHelper;
use Alfa\Component\Alfa\Administrator\Helper\TourHelper;
use Alfa\Component\Alfa\Administrator\Helper\UserInfoHelper;
use Alfa\Component\Alfa\Administrator\Service\PriceIndexSyncService;
use Exception;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Event\Menu\PreprocessMenuItemsEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Router\Route;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use Joomla\Registry\Registry;
use Throwable;

/**
 * Alfa Commerce: central sync system plugin.
 *
 * @since  1.0.0
 */
class Alfasync extends CMSPlugin implements SubscriberInterface
{
    /** @var bool */
    protected $autoloadLanguage = true;

    /**
     * The set of pricing groups each account held when its save began, by user id.
     *
     * @var array<int, string>
     * @since  2.0.0
     */
    private array $groupSetBeforeSave = [];

    // Event subscriptions: the only place to touch when adding a sync target

    /**
     * @return array<string, string>
     * @since  1.0.0
     */
    public static function getSubscribedEvents(): array
    {
        return [
            // User events: always active
            'onUserBeforeSave' => 'onUserBeforeSave',
            'onUserAfterSave' => 'onUserAfterSave',
            'onAfterRoute' => 'onAfterRoute',
            'onUserAfterSaveGroup' => 'onUserAfterSaveGroup',
            'onUserAfterDeleteGroup' => 'onUserAfterDeleteGroup',
            'onUserAfterDelete' => 'onUserAfterDelete',

            // The users component cleaned its cache: accounts may have changed groups
            // in bulk (Users > Batch writes the memberships without a user event).
            'onContentCleanCache' => 'onContentCleanCache',

            // A site menu item saved -> the setup checklist re-checks the storefront links.
            'onContentAfterSave' => 'onContentAfterSave',

            // Hide the com_alfa "Tools" admin menu item from users without `alfa.tools`.
            'onPreprocessMenuItems' => 'onPreprocessMenuItems',

            // Carry Alfa's tour grammar onto admin screens that are not ours.
            'onBeforeCompileHead' => 'onBeforeCompileHead',

            // Storefront menu items removed -> the setup checklist must un-tick.
            'onContentAfterDelete' => 'onContentAfterDelete',

            // Gateway plugins toggled / com_alfa Options saved -> setup verdict stale.
            'onExtensionAfterSave' => 'onExtensionAfterSave',

            // A guided tour finished/skipped -> the checklist's tour-first buttons flip.
            'onAfterTourSaveUserState' => 'onAfterTourSaveUserState',

            // Custom Alfa events: uncomment + dispatch from component/plugin
            // 'onAlfaProductAfterSave'  => 'onAlfaProductAfterSave',
            // 'onAlfaOrderAfterSave'    => 'onAlfaOrderAfterSave',
            // 'onAlfaCategoryAfterSave' => 'onAlfaCategoryAfterSave',
        ];
    }

    // User event handlers

    /**
     * Called before a Joomla user is saved (registration, profile edit, admin save).
     *
     * Remembers the set of pricing groups the account holds, so the handler that
     * runs after the save can tell whether the account moved to another set. A
     * new account has none yet.
     *
     * @param Event|array $event
     *
     *
     * @since  2.0.0
     */
    public function onUserBeforeSave($event): void
    {
        [$user, $isNew] = $this->extractUserArgs(event: $event, isNew: null, success: null);
        $userId = (int) ($user['id'] ?? 0);

        if ($isNew || $userId <= 0) {
            return;
        }

        try {
            $this->groupSetBeforeSave[$userId] = (new PriceIndexSyncService())->accountGroupSet(userId: $userId);
        } catch (Throwable $e) {
            $this->logError(caller: 'onUserBeforeSave', message: $e->getMessage(), context: ['user_id' => $userId]);
        }
    }

    /**
     * Called after a Joomla user is saved (registration, profile edit, admin save).
     *
     * Available in $data:
     *   id, name, username, email, password, block, sendEmail,
     *   registerDate, lastvisitDate, activation, params, groups, ...
     *
     * @param Event|array $event
     * @since  1.0.0
     */
    public function onUserAfterSave($event, ?bool $isNew = null, ?bool $success = null): void
    {
        [$data, $isNew, $success] = $this->extractUserArgs($event, $isNew, $success);

        if (!$success || empty($data['id'])) {
            return;
        }

        $userId = (int) $data['id'];

        try {
            $inserted = SyncHelper::insertAlfaUser($this->getDatabase(), $userId);
            $this->logDebug('onUserAfterSave', ($inserted ? 'Inserted' : 'Already exists') . ': user #' . $userId);
        } catch (Exception $e) {
            $this->logError('onUserAfterSave', $e->getMessage(), ['user_id' => $userId]);
        }

        $this->followAccount(caller: 'onUserAfterSave', userId: $userId);

        // Save alfa registration field values if this came from our registration form.
        $jform = Factory::getApplication()->input->post->get('jform', [], 'array');
        $alfaFields = $jform[FieldsHelper::FIELDS_KEY] ?? null;

        if (empty($alfaFields)) {
            return;
        }

        try {
            UserInfoHelper::insertData($userId, $alfaFields);
            $this->logDebug('onUserAfterSave', 'Saved alfa user info for user #' . $userId);
        } catch (Exception $e) {
            $this->logError('onUserAfterSave', $e->getMessage(), ['user_id' => $userId]);
        }
    }

    /**
     * Alfa's guided tours do not stay at home: the storefront-menu ones are built
     * entirely out of com_menus screens, and one of their steps lives inside the
     * type window's iframe. The component's own dispatcher cannot reach those pages,
     * so without this the learner meets a press step with no Next button and no way
     * on except finding the control themselves, which is exactly what the grammar
     * supplement exists to prevent.
     *
     * Cheap and quiet: skipped on our own pages (the dispatcher already did it),
     * on anything but HTML, when core's tours plugin is off, and for users with no
     * business in com_alfa. The script itself does nothing until a com_alfa tour is
     * actually running.
     *
     *
     * @since  2.0.0
     */
    public function onBeforeCompileHead(): void
    {
        try {
            $app = Factory::getApplication();

            if (!$app->isClient('administrator') || $app->getInput()->getCmd('format', 'html') !== 'html') {
                return;
            }

            if ($app->getInput()->getCmd('option', '') === 'com_alfa') {
                return;
            }

            if (!PluginHelper::isEnabled('system', 'guidedtours')) {
                return;
            }

            $user = $app->getIdentity();

            if (!$user || $user->guest || !$user->authorise('core.manage', 'com_alfa')) {
                return;
            }

            TourHelper::attachGrammar($app->getDocument());
        } catch (Throwable) {
            // Tour polish never costs a page.
        }
    }

    /**
     * Redirects the stock com_users registration page to our com_alfa one.
     * Login, profile, MFA (methods/method/captive), reset and remind are NOT touched.
     *
     * @since  1.0.1
     */
    public function onAfterRoute(): void
    {
        $app = Factory::getApplication();

        if (!$app->isClient('site')) {
            return;
        }

        $input = $app->input;

        if ($input->getCmd('option') !== 'com_users' || $input->getCmd('view') !== 'registration') {
            return;
        }

        // Let activation and the post-submit complete screen through.
        if ($input->getCmd('task') === 'registration.activate' || $input->getCmd('layout') === 'complete') {
            return;
        }

        $url = 'index.php?option=com_alfa&view=registration';
        $return = $input->getBase64('return', '');

        if ($return !== '') {
            $url .= '&return=' . $return;
        }

        $app->redirect(Route::_($url, false));
    }

    /**
     * Called after a Joomla usergroup is saved (Users -> Groups).
     *
     * Available in $group: id, title, parent_id.
     *
     * @param Event|array $event
     * @since  1.0.0
     */
    public function onUserAfterSaveGroup($event, ?bool $isNew = null): void
    {
        [$group, $isNew] = $this->extractGroupArgs($event, $isNew);

        if (empty($group['id'])) {
            return;
        }

        $groupId = (int) $group['id'];

        try {
            // Pass null -> SyncHelper reads prices_display defaults from
            // the seeded com_alfa component params via ComponentHelper.
            $inserted = SyncHelper::insertAlfaUsergroup($this->getDatabase(), $groupId);
            $this->logDebug('onUserAfterSaveGroup', ($inserted ? 'Inserted' : 'Already exists') . ': group #' . $groupId);
        } catch (Exception $e) {
            $this->logError('onUserAfterSaveGroup', $e->getMessage(), ['group_id' => $groupId]);
        }

        // A group given another parent is followed by onContentCleanCache: the
        // users component cleans its cache after every save of a group.
    }

    /**
     * Called after a Joomla usergroup is deleted (Users -> Groups). Joomla
     * removes the groups under it with it.
     *
     * @param Event|array $event
     *
     *
     * @since  2.0.0
     */
    public function onUserAfterDeleteGroup($event): void
    {
        [$group] = $this->extractGroupArgs(event: $event, isNew: null);

        if ((int) ($group['id'] ?? 0) > 0) {
            $this->followCustomerGroups(caller: 'onUserAfterDeleteGroup');
        }
    }

    /**
     * One account was saved: when it now holds another set of pricing groups
     * than before, or a set the price index does not know yet, have the index
     * follow. A registration into the usual group, or a profile saved with the
     * same groups, costs nothing more.
     *
     * @param string $caller The handler, for the log.
     * @param int $userId The account.
     *
     *
     * @since  2.0.0
     */
    private function followAccount(string $caller, int $userId): void
    {
        $before = $this->groupSetBeforeSave[$userId] ?? null;
        unset($this->groupSetBeforeSave[$userId]);

        try {
            $index = new PriceIndexSyncService();
            $now = $index->accountGroupSet(userId: $userId);

            if ($now !== $before || !$index->holdsGroupSet(groupSet: $now)) {
                $this->followCustomerGroups(caller: $caller);
            }
        } catch (Throwable $e) {
            $this->logError(caller: $caller, message: $e->getMessage(), context: ['user_id' => $userId]);
        }
    }

    /**
     * The groups customers hold may have changed: have the price index request
     * the sets of pricing groups they now hold that it does not know (the
     * scheduled task prepares them) and drop the ones nobody holds. Never
     * breaks the caller's save.
     *
     * @param string $caller The handler, for the log.
     *
     *
     * @since  2.0.0
     */
    private function followCustomerGroups(string $caller): void
    {
        try {
            $requested = (new PriceIndexSyncService())->syncGroupSets();
            $this->logDebug(caller: $caller, message: 'Price index followed the customers\' groups: ' . $requested . ' set(s) requested');
        } catch (Throwable $e) {
            $this->logError(caller: $caller, message: $e->getMessage());
        }
    }

    /**
     * Called when a component's cache is cleaned. The users component cleans it
     * after an account is saved, deleted, blocked or activated in the back office,
     * after a group is saved (given another parent included) and after Users >
     * Batch, which places accounts in groups or takes them out without any user
     * event: have the price index follow the groups customers now hold. A group's
     * removal does not clean it; onUserAfterDeleteGroup follows that.
     *
     * @param Event|array $event
     *
     *
     * @since  2.0.0
     */
    public function onContentCleanCache($event): void
    {
        $group = $event instanceof Event
            ? (string) ($event->getArgument('defaultgroup') ?? '')
            : (string) (((array) $event)['defaultgroup'] ?? '');

        if ($group === 'com_users') {
            $this->followCustomerGroups(caller: 'onContentCleanCache');
        }
    }

    /**
     * Called after a Joomla user is deleted.
     *
     * @param Event|array $event
     * @since  1.0.0
     */
    public function onUserAfterDelete($event, ?bool $success = null): void
    {
        if ($event instanceof Event) {
            $user = (array) ($event->getArgument('subject') ?? $event->getArgument('0') ?? []);
            $success = (bool) ($event->getArgument('1') ?? $event->getArgument('success') ?? true);
        } else {
            $user = (array) $event;
            $success = (bool) $success;
        }

        if (!$success || empty($user['id'])) {
            return;
        }

        $userId = (int) $user['id'];

        // Every step below uses the connection, so without one there is nothing to do but log it.
        try {
            $db = $this->getDatabase();
        } catch (Throwable $e) {
            $this->logError('onUserAfterDelete', $e->getMessage(), ['user_id' => $userId]);

            return;
        }

        try {
            $query = $db->getQuery(true)
                ->delete($db->quoteName('#__alfa_users'))
                ->where($db->quoteName('id_user') . ' = ' . $userId);

            $db->setQuery($query)->execute();
            $this->logDebug('onUserAfterDelete', 'Deleted alfa user #' . $userId);
        } catch (Exception $e) {
            $this->logError('onUserAfterDelete', $e->getMessage(), ['user_id' => $userId]);
        }

        // The account is gone; its orders are not. Clearing id_user is what
        // tells every consumer "account deleted" (the order keeps its own
        // customer snapshot) instead of pointing at an id that now means nothing
        // or, worse, a future user given the same id.
        try {
            $query = $db->getQuery(true)
                ->update($db->quoteName('#__alfa_orders'))
                ->set($db->quoteName('id_user') . ' = NULL')
                ->where($db->quoteName('id_user') . ' = ' . $userId);

            $db->setQuery($query)->execute();
            $this->logDebug('onUserAfterDelete', 'Detached orders of deleted user #' . $userId);
        } catch (Throwable $e) {
            $this->logError('onUserAfterDelete', $e->getMessage(), ['user_id' => $userId, 'table' => 'orders']);
        }

        // Cascade-equivalent cleanup for the order-status admin recipients
        // join table. No DB-level FK there (MyISAM compatibility on the
        // parent table), so we mirror what #__alfa_users cleanup does.
        try {
            $query = $db->getQuery(true)
                ->delete($db->quoteName('#__alfa_orderstatus_recipients'))
                ->where($db->quoteName('id_user') . ' = ' . $userId);

            $db->setQuery($query)->execute();
            $this->logDebug('onUserAfterDelete', 'Dropped order-status recipient memberships for user #' . $userId);
        } catch (Exception $e) {
            $this->logError('onUserAfterDelete', $e->getMessage(), ['user_id' => $userId, 'table' => 'orderstatus_recipients']);
        }

        // The account's set of pricing groups may be held by nobody now.
        $this->followCustomerGroups(caller: 'onUserAfterDelete');
    }

    // Content event handlers: uncomment in getSubscribedEvents() to activate

    /**
     * Called after any content item is saved: a site menu item re-checks the
     * storefront links of the setup checklist ({@see self::handleContentSave()}).
     * A content language is not a content save; see {@see self::onExtensionAfterSave()}.
     *
     * @param Event|array $event
     * @since  1.0.0
     */
    public function onContentAfterSave($event): void
    {
        // Prefer the typed accessor (Joomla\CMS\Event\Model\AfterSaveEvent::getContext());
        // fall back to positional args for legacy / generic Event dispatch.
        $context = ($event instanceof Event && method_exists($event, 'getContext'))
            ? (string) $event->getContext()
            : (string) (is_array($event) ? ($event[0] ?? '') : '');

        $this->handleContentSave($context);
    }

    /**
     * Route a content-save context to the relevant Alfa sync.
     *
     * Kept as a separate dispatcher so more contexts can be added over time
     * (each with its own guard + SyncHelper call) without touching the event
     * subscription wiring above.
     *
     * @param string $context Joomla content context, e.g. 'com_languages.language'.
     * @since  1.0.0
     */
    private function handleContentSave(string $context): void
    {
        switch ($context) {
            // A content language was added/edited -> (re)create the per-language
            // translation tables for every translatable Alfa entity.
            case 'com_languages.language':
                try {
                    $result = SyncHelper::syncLanguageSchema();
                    $this->logDebug('handleContentSave', 'Language schema synced: ' . count($result['tables']) . ' table(s).');
                } catch (Exception $e) {
                    $this->logError('handleContentSave', $e->getMessage(), ['context' => $context]);
                }
                break;

                // A site menu item changed -> a storefront link may have (dis)appeared,
                // which the setup checklist's `storefront.menu` detector watches.
            case 'com_menus.item':
                $this->invalidateSetup('menu item saved');
                break;

                // Future: add more content contexts that should trigger a sync here.
        }
    }

    /**
     * Called after any content item is deleted. Only storefront menu items matter
     * here: removing the shop or cart link must un-tick the setup checklist.
     *
     * @param Event|array $event
     * @since  2.0.0
     */
    public function onContentAfterDelete($event): void
    {
        $context = ($event instanceof Event && method_exists($event, 'getContext'))
            ? (string) $event->getContext()
            : (string) (is_array($event) ? ($event[0] ?? '') : '');

        if ($context === 'com_menus.item') {
            $this->invalidateSetup('menu item deleted');
        }
    }

    /**
     * Called after an extension row is saved. Two saves flip setup detectors without
     * touching any Alfa table: enabling/disabling an alfa-payments or alfa-shipments
     * plugin (the correctness join in the payments/shipping checks), and saving
     * com_alfa's own Options (store identity, default currency, media location).
     *
     * Languages: Content Languages announces its saves here too (com_languages
     * LanguageModel: onExtensionAfterSave, never onContentAfterSave): a content
     * language added or edited gets its #__alfa_*_<langtag> tables, through the same
     * routine the installer and the admin "Resync languages" button use.
     *
     * @param Event|array $event
     * @since  2.0.0
     */
    public function onExtensionAfterSave($event): void
    {
        if ($event instanceof Event) {
            $context = method_exists($event, 'getContext')
                ? (string) $event->getContext()
                : (string) ($event->getArgument('context') ?? $event->getArgument('0') ?? '');
            $item = method_exists($event, 'getItem')
                ? $event->getItem()
                : ($event->getArgument('subject') ?? $event->getArgument('1') ?? null);
        } else {
            $context = (string) (is_array($event) ? ($event[0] ?? '') : '');
            $item = is_array($event) ? ($event[1] ?? null) : null;
        }

        if ($context === 'com_languages.language') {
            $this->handleContentSave($context);

            return;
        }

        if ($context === 'com_config.component') {
            $component = (string) ($item->element ?? $item->name ?? '');

            if ($component === 'com_alfa') {
                $this->invalidateSetup('com_alfa options saved');
            }

            // The guest group, or the group new accounts are placed in, may be another one.
            // This request read the options before they were saved: it takes the saved
            // ones, so the sets requested are those of the options as they are now.
            if ($component === 'com_users') {
                ComponentHelper::getComponent('com_users')->setParams(new Registry($item->params ?? '{}'));
                $this->followCustomerGroups(caller: 'onExtensionAfterSave');
            }

            return;
        }

        if ($context === 'com_plugins.plugin') {
            $folder = (string) ($item->folder ?? '');

            if (in_array($folder, ['alfa-payments', 'alfa-shipments'], true)) {
                $this->invalidateSetup('gateway plugin saved');
            }
        }
    }

    /**
     * Called when com_guidedtours records a per-user tour outcome (completed,
     * skipped, delayed). Only OUR tours matter: the setup card renders its
     * tour-first buttons from that state, so the cached verdict must re-derive.
     *
     * @param Event|array $event
     * @since  2.0.0
     */
    public function onAfterTourSaveUserState($event): void
    {
        try {
            $tourId = 0;

            if ($event instanceof Event) {
                $tourId = (int) ($event->getArgument('tourId') ?? $event->getArgument('0') ?? 0);
            } elseif (is_array($event)) {
                $tourId = (int) ($event[0] ?? 0);
            }

            if ($tourId <= 0) {
                return;
            }

            $db = $this->getDatabase();
            $uid = (string) $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName('uid'))
                    ->from($db->quoteName('#__guidedtours'))
                    ->where($db->quoteName('id') . ' = ' . $tourId),
            )->loadResult();

            if (str_starts_with($uid, 'com_alfa.')) {
                $this->invalidateSetup('tour state saved: ' . $uid);
            }
        } catch (Throwable $e) {
            $this->logError('onAfterTourSaveUserState', $e->getMessage());
        }
    }

    /**
     * Drop the cached setup verdict; the next admin page recomputes and reconciles
     * the `alfa.setup` notification. Never breaks the caller's save.
     *
     * @param string $why Log context.
     * @since  2.0.0
     */
    private function invalidateSetup(string $why): void
    {
        try {
            SetupHelper::invalidate();
            $this->logDebug('invalidateSetup', $why);
        } catch (Throwable $e) {
            $this->logError('invalidateSetup', $e->getMessage(), ['why' => $why]);
        }
    }

    // Admin menu handlers

    /**
     * Hide the com_alfa "Tools" admin menu item from users without `alfa.tools`.
     * Cosmetic only: the Tools view + its controller enforce the same permission.
     *
     * Flagging an item with menu_show=0 makes Joomla's CssMenu drop it.
     *
     * @param PreprocessMenuItemsEvent $event The admin-menu preprocess event.
     *
     *
     * @since  1.0.0
     */
    public function onPreprocessMenuItems(PreprocessMenuItemsEvent $event): void
    {
        if ($event->getContext() !== 'com_menus.administrator.module') {
            return;
        }

        // Permitted users keep the item; resolve the permission once.
        $canSeeTools = Factory::getApplication()->getIdentity()->authorise('alfa.tools', 'com_alfa');

        if ($canSeeTools) {
            return;
        }

        foreach ($event->getItems() as $item) {
            $link = (string) ($item->link ?? '');
            $isToolsMenuItem = str_contains($link, 'option=com_alfa') && str_contains($link, 'view=tools');

            if ($isToolsMenuItem) {
                $item->getParams()->set('menu_show', 0);
            }
        }
    }

    // Custom Alfa event handlers: uncomment in getSubscribedEvents() to activate

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

    // Argument extraction helpers

    /**
     * Extracts [userData, isNew, success] from a typed Event or legacy array call.
     *
     * @return array{0: array, 1: bool, 2: bool}
     * @since  1.0.0
     */
    private function extractUserArgs($event, ?bool $isNew, ?bool $success): array
    {
        if ($event instanceof Event) {
            $data = (array) ($event->getArgument('subject') ?? $event->getArgument('0') ?? []);
            $isNew = (bool) ($event->getArgument('1') ?? $event->getArgument('isNew') ?? false);
            $success = (bool) ($event->getArgument('2') ?? $event->getArgument('success') ?? true);
        } else {
            $data = (array) $event;
            $isNew = (bool) $isNew;
            $success = (bool) $success;
        }

        return [$data, $isNew, $success];
    }

    /**
     * Extracts [groupData, isNew] from a typed Event or legacy array call.
     *
     * @return array{0: array, 1: bool}
     * @since  1.0.0
     */
    private function extractGroupArgs($event, ?bool $isNew): array
    {
        if ($event instanceof Event) {
            $group = (array) ($event->getArgument('subject') ?? $event->getArgument('0') ?? []);
            $isNew = (bool) ($event->getArgument('1') ?? $event->getArgument('isNew') ?? false);
        } else {
            $group = (array) $event;
            $isNew = (bool) $isNew;
        }

        return [$group, $isNew];
    }

    // Database helper

    /**
     * Returns the DB driver. Prefers the injected $this->db (Joomla 4.2+).
     *
     * @return \Joomla\Database\DatabaseInterface
     * @since  1.0.0
     */
    private function getDatabase()
    {
        if (isset($this->db) && $this->db !== null) {
            return $this->db;
        }

        return Factory::getContainer()->get('DatabaseDriver');
    }

    // Logging

    private function logError(string $caller, string $message, array $context = []): void
    {
        $this->writeLog(Log::ERROR, $caller, $message, $context);
    }

    private function logDebug(string $caller, string $message): void
    {
        if (!(bool) $this->params->get('debug_log', 0)) {
            return;
        }

        $this->writeLog(Log::DEBUG, $caller, $message);
    }

    private function writeLog(int $level, string $caller, string $message, array $context = []): void
    {
        Log::addLogger(['text_file' => 'alfa_sync.php'], Log::ALL, ['alfa_sync']);

        $ctx = empty($context) ? '' : ' | ' . json_encode($context);
        Log::add('[' . $caller . '] ' . $message . $ctx, $level, 'alfa_sync');
    }
}
