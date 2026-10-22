<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Maintenance;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\SyncHelper;
use Alfa\Component\Alfa\Administrator\Service\Download\Storage;
use Alfa\Component\Alfa\Administrator\Service\Setup\ComponentOptions;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use RuntimeException;

/**
 * Reset to default: keep the shop and its data exactly as they are, and give Alfa's configurable
 * settings back their installed defaults.
 *
 * WHAT IT CHANGES, and nothing else:
 *   - the component Options, to what an installation of this version writes
 *     ({@see ComponentOptions::DEFAULTS}); an Option with no installed value is removed, so it
 *     reads its built-in default, as on a fresh install. Three are kept as they are: the download
 *     store's path (the store, and every file in it, stays where it is), the delivery destination
 *     the merchant mapped (a structural choice of the shop, not a default: kept, mapped or not), and
 *     the records kept among the Options ({@see ComponentOptions::CARRIED}: which menu items the
 *     setup created);
 *   - the price display of every customer group, the setting built from those Options: built
 *     again from the defaults, and each group following the shop's Options again.
 * The Options are saved through Joomla's own Options save, so whatever listens to an Options save
 * (the setup checklist among it) hears this one too.
 *
 * WHAT IT NEVER TOUCHES: catalogue, customers, orders, documents, payments, credit, stock, history
 * or files; a row other rows refer to (currencies, statuses, places, document types, form fields);
 * scheduled tasks, guided tours, the enabled state of an extension, a user's own screen state, the
 * settings of another extension, permissions.
 *
 * A derived index that a changed Option makes stale is rebuilt by its own tool: the price index
 * follows `combination_adjust_target`, and the result says when Tools > Rebuild projections is
 * owed.
 *
 * @since  2.1.0
 */
final class ResetToDefault
{
    /**
     * Options the price index is computed from.
     *
     * @var string[]
     *
     * @since  2.1.0
     */
    public const PRICE_INDEX_KEYS = ['combination_adjust_target'];

    /**
     * The site's connection.
     *
     *
     * @since  2.1.0
     */
    private DatabaseInterface $db;

    /**
     * @param DatabaseInterface|null $db The site's connection; the container's by default.
     *
     * @since  2.1.0
     */
    public function __construct(?DatabaseInterface $db = null)
    {
        $this->db = $db ?? Factory::getContainer()->get(DatabaseInterface::class);
    }

    /**
     * Whether a user may reset the settings: the Tools permission, and Joomla's Configure
     * permission of Alfa, the one that guards the Options.
     *
     * @param User $user The user.
     *
     * @since  2.1.0
     */
    public static function permitted(User $user): bool
    {
        return $user->authorise('alfa.tools', 'com_alfa') && $user->authorise('core.admin', 'com_alfa');
    }

    /**
     * The Options as Reset to default would write them.
     *
     * @return array<string, mixed>
     *
     * @since  2.1.0
     */
    public function target(): array
    {
        $stored = ComponentOptions::stored(db: $this->db);
        $target = ComponentOptions::DEFAULTS + ComponentOptions::carried(stored: $stored);
        $store = Storage::currentRoot();

        if ($store !== null) {
            $target['downloads_storage_path'] = $store;
        }

        // The save replaces the Options wholesale: the mapping is written back as it is, or it would
        // be lost. An unmapped shop stays unmapped.
        if (\array_key_exists('delivery_place_field', $stored)) {
            $target['delivery_place_field'] = $stored['delivery_place_field'];
        }

        return $target;
    }

    /**
     * Every setting Reset to default would change, current value and default, the confirmation
     * shows exactly this.
     *
     * @return array{options: list<array{key: string, current: mixed, default: mixed}>, groups: list<array{id: int, title: string, current: array{enable: int, display: string}, default: array{enable: int, display: string}}>}
     *
     * @since  2.1.0
     */
    public function preview(): array
    {
        $current = ComponentOptions::stored(db: $this->db);
        $target = $this->target();
        $options = [];
        $keys = array_unique([...array_keys($target), ...array_keys($current)]);

        foreach ($keys as $key) {
            $now = \array_key_exists($key, $current) ? $current[$key] : null;
            $default = \array_key_exists($key, $target) ? $target[$key] : null;

            if (self::same(current: $now, hasCurrent: \array_key_exists($key, $current), default: $default, hasDefault: \array_key_exists($key, $target))) {
                continue;
            }

            $options[] = ['key' => (string) $key, 'current' => $now, 'default' => $default];
        }

        usort($options, static fn (array $a, array $b): int => strcmp($a['key'], $b['key']));

        $display = SyncHelper::buildDefaultPricesDisplay(sourceParams: ComponentOptions::DEFAULTS);
        $groups = [];
        $db = $this->db;
        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select([$db->quoteName('a.usergroup_id', 'id'), $db->quoteName('g.title'), $db->quoteName('a.prices_enable'), $db->quoteName('a.prices_display')])
                ->from($db->quoteName('#__alfa_usergroups', 'a'))
                ->join('LEFT', $db->quoteName('#__usergroups', 'g'), $db->quoteName('g.id') . ' = ' . $db->quoteName('a.usergroup_id'))
                ->order($db->quoteName('a.usergroup_id') . ' ASC'),
        )->loadObjectList() ?: [];

        foreach ($rows as $row) {
            $sameDisplay = json_decode((string) $row->prices_display, true) == json_decode($display, true);

            if ((int) $row->prices_enable === 0 && $sameDisplay) {
                continue;
            }

            $groups[] = [
                'id' => (int) $row->id,
                'title' => (string) ($row->title ?? ''),
                'current' => ['enable' => (int) $row->prices_enable, 'display' => (string) $row->prices_display],
                'default' => ['enable' => 0, 'display' => $display],
            ];
        }

        return ['options' => $options, 'groups' => $groups];
    }

    /**
     * Reset the settings. The answer says what changed, whether the price index must be rebuilt, and
     * why the audit record could not be written when it could not (the settings are reset all the
     * same).
     *
     * @param User $user Who resets them.
     *
     * @return array{options: int, groups: int, keys: string[], rebuild: bool, audit: string|null}
     *
     * @throws RuntimeException When the user may not, or Joomla's Options save refuses.
     *
     * @since  2.1.0
     */
    public function apply(User $user): array
    {
        if (!self::permitted(user: $user)) {
            throw new RuntimeException(Text::_('COM_ALFA_RESET_ERROR_NOT_PERMITTED'), 403);
        }

        if (MaintenanceState::active()) {
            throw new RuntimeException(Text::_('COM_ALFA_CLEAR_ERROR_IN_MAINTENANCE'), 409);
        }

        $before = $this->preview();
        $keys = array_column($before['options'], 'key');

        // Joomla's own Options save: the same path, events and cache cleaning as the Options form.
        $app = Factory::getApplication();
        $app->getLanguage()->load('com_config', JPATH_ADMINISTRATOR);
        $model = $app->bootComponent('com_config')->getMVCFactory()
            ->createModel('Component', 'Administrator', ['ignore_request' => true]);
        $model->save([
            'id' => (int) $this->db->setQuery(
                $this->db->getQuery(true)
                    ->select($this->db->quoteName('extension_id'))
                    ->from($this->db->quoteName('#__extensions'))
                    ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('component'))
                    ->where($this->db->quoteName('element') . ' = ' . $this->db->quote('com_alfa')),
            )->loadResult(),
            'option' => 'com_alfa',
            'params' => $this->target(),
        ]);

        ComponentOptions::forgetCachedRecords();

        $groups = $before['groups'] === [] ? 0 : SyncHelper::applyDefaultPricesDisplay(db: $this->db, sourceParams: ComponentOptions::DEFAULTS);
        $rebuild = array_intersect($keys, self::PRICE_INDEX_KEYS) !== [];
        $run = gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(4));
        $audit = null;

        // The settings are reset by now: an audit record that cannot be written is said apart, and
        // never turns the reset into a failure.
        try {
            MaintenanceAudit::record(
                languageKey: 'COM_ALFA_ACTIONLOG_OPTIONS_RESET',
                data: ['run' => $run, 'options' => \count($keys), 'groups' => $groups],
                user: $user,
                line: sprintf('Reset to default: %d Options (%s) and the price display of %d customer groups.', \count($keys), implode(', ', $keys) ?: 'none', $groups),
            );
        } catch (RuntimeException $e) {
            $audit = $e->getMessage();
        }

        return ['options' => \count($keys), 'groups' => $groups, 'keys' => $keys, 'rebuild' => $rebuild, 'audit' => $audit];
    }

    /**
     * Whether a stored value and a default are the same setting: a scalar compared as the text
     * the Options form stores (1 and "1" are one value), a list compared as a list.
     *
     * @param mixed $current The stored value.
     * @param bool $hasCurrent Whether it is stored at all.
     * @param mixed $default The default.
     * @param bool $hasDefault Whether there is one.
     *
     * @since  2.1.0
     */
    private static function same(mixed $current, bool $hasCurrent, mixed $default, bool $hasDefault): bool
    {
        if ($hasCurrent !== $hasDefault) {
            return false;
        }

        return self::normal($current) === self::normal($default);
    }

    /**
     * A value as the Options form stores it: scalars as text, lists and objects by their content.
     *
     * @param mixed $value The value.
     *
     * @since  2.1.0
     */
    private static function normal(mixed $value): string
    {
        if (\is_array($value) || \is_object($value)) {
            $walk = static function (mixed $item) use (&$walk): mixed {
                return \is_array($item) || \is_object($item) ? array_map($walk, (array) $item) : (string) $item;
            };

            return 'list:' . json_encode($walk($value));
        }

        return 'text:' . (string) $value;
    }
}
