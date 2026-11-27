<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Outbound\OutboundFeed;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;

/**
 * The consumers of the shop's changes the Background screen filters the outgoing notifications by: every webhook and
 * every extension that tells another system, by name.
 *
 * @since  2.3.0
 */
class OutboundconsumerField extends ListField
{
    /**
     * The field type.
     *
     * @var string
     *
     * @since  2.3.0
     */
    protected $type = 'outboundconsumer';

    /**
     * The empty option plus one per consumer, by name.
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
                ->select($db->quoteName(['id', 'name']))
                ->from($db->quoteName(OutboundFeed::CONSUMERS))
                ->order($db->quoteName('name') . ' ASC, ' . $db->quoteName('id') . ' ASC'),
        )->loadAssocList() ?: [];
        $options = [HTMLHelper::_('select.option', '', Text::_('COM_ALFA_OUTBOUND_FILTER_CONSUMER'))];

        foreach ($rows as $row) {
            $options[] = HTMLHelper::_('select.option', (int) $row['id'], (string) $row['name']);
        }

        return array_merge(parent::getOptions(), $options);
    }
}
