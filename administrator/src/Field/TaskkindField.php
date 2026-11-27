<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Plugin\AlfaPluginHelper;
use Alfa\Component\Alfa\Administrator\Plugin\PluginId;
use Alfa\Component\Alfa\Administrator\Service\Task\DueRead;
use Alfa\Component\Alfa\Administrator\Service\Task\Runner;
use Alfa\Component\Alfa\Administrator\Service\Task\Tasks;
use Alfa\Component\Alfa\Administrator\Service\Task\WorkDeclarations;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;

/**
 * The kinds of background work the Background screen filters by: every handler with a row in the tasks table, named
 * as its policy names it, an extension's with its extension's name; a handler this version does not run is named by
 * its code.
 *
 * @since  2.3.0
 */
class TaskkindField extends ListField
{
    /**
     * The field type.
     *
     * @var string
     *
     * @since  2.3.0
     */
    protected $type = 'taskkind';

    /**
     * The empty option plus one per handler present, sorted by name.
     *
     * @return array The option set.
     *
     * @since  2.3.0
     */
    protected function getOptions(): array
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select('DISTINCT ' . implode(', ', $db->quoteName(['handler', 'owner', 'type'])))
                ->from($db->quoteName(Tasks::TABLE)),
        )->loadAssocList() ?: [];
        $names = [];

        foreach ($rows as $row) {
            $handler = (string) $row['handler'];
            $owner = (string) $row['owner'];
            WorkDeclarations::loadLanguage(owner: $owner);
            $policy = (string) $row['type'] === Tasks::TYPE_JOB
                ? Runner::handler(name: $handler, owner: $owner)?->policy()
                : Tasks::handler(name: $handler, owner: $owner)?->policy();
            $name = ($policy->label ?? '') !== '' ? Text::_($policy->label) : $handler;

            if ($owner !== DueRead::CORE_OWNER) {
                $name .= ' (' . AlfaPluginHelper::get()->name(PluginId::of($owner)) . ')';
            }

            $names[$handler] = $name;
        }

        asort($names, SORT_NATURAL | SORT_FLAG_CASE);
        $options = [HTMLHelper::_('select.option', '', Text::_('COM_ALFA_TASKS_FILTER_KIND'))];

        foreach ($names as $handler => $name) {
            $options[] = HTMLHelper::_('select.option', $handler, $name);
        }

        return array_merge(parent::getOptions(), $options);
    }
}
