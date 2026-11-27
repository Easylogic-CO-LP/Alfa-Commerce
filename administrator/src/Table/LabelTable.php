<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Table;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Catalog\PublishPeriod;
use Alfa\Component\Alfa\Site\Service\Catalog\LabelCorner;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseDriver;

/**
 * Label table: one of the shop's labels, a badge a product ticks.
 *
 * @since  2.3.0
 */
class LabelTable extends Table
{
    /**
     * Indicates that columns fully support the NULL value in the database
     *
     * @var bool
     * @since  2.3.0
     */
    protected $_supportNullValue = true;

    /**
     * Constructor
     *
     * @param DatabaseDriver $db A database connector object
     *
     * @since  2.3.0
     */
    public function __construct(DatabaseDriver $db)
    {
        $this->typeAlias = 'com_alfa.label';
        parent::__construct('#__alfa_labels', 'id', $db);
        $this->setColumnAlias('published', 'state');
    }

    /**
     * The row's contract for every writer, the form and the REST API alike: the corner is one of the four (top left
     * for none), the colours and the class are trimmed, and a publishing period ends after it starts.
     *
     * @return bool True when the row may be stored.
     *
     * @since  2.3.0
     */
    public function check()
    {
        if (!parent::check()) {
            return false;
        }

        $this->corner = LabelCorner::of(isset($this->corner) ? (string) $this->corner : null)->value;
        $this->bg = trim((string) ($this->bg ?? ''));
        $this->color = trim((string) ($this->color ?? ''));
        $this->class = trim((string) ($this->class ?? ''));

        foreach (['list_slot', 'item_slot'] as $slot) {
            $this->$slot = trim((string) ($this->$slot ?? '')) ?: 'image';
        }

        if (!PublishPeriod::endsAfterStart(start: $this->publish_up ?? null, end: $this->publish_down ?? null)) {
            $this->setError(Text::_('COM_ALFA_ERROR_LABEL_PERIOD_END_BEFORE_START'));

            return false;
        }

        return true;
    }
}
