<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field\Traits;

\defined('_JEXEC') or die;

use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\Registry\Registry;
use UnexpectedValueException;

/**
 * A field that reads what it shows from the database fails closed when it cannot read it.
 *
 * A picker posts only the stored values it lists, and a composer posts only the content it
 * shows. Listing or showing none, it posts an empty choice, and the save writes that over the
 * stored one: a method limited to some places becomes one for every place. So a failed read is
 * not an empty list: the administrator form says so, and the field refuses the save, which
 * leaves the stored value as it is. A read that finds no records is an empty list, as before.
 *
 * The field gives what it read through loadModelRows(): an array, empty when there are no
 * records, or null when they could not be read. A field whose sentence is not about choices
 * names its own language key in notLoadedKey().
 *
 * @since  2.1.1
 */
trait HasFailClosedOptionsTrait
{
    /**
     * The rows the field's options (or its content) are built from.
     *
     * @return array|null The rows (empty when there are none), or null when they could not be read.
     *
     * @since  2.1.0
     */
    abstract protected function loadModelRows(): ?array;

    /**
     * Say on the administrator form that this field's options could not be loaded.
     *
     * A storefront filter keeps its empty list: the visitor can do nothing about it.
     *
     *
     * @since  2.1.0
     */
    protected function reportOptionsNotLoaded(): void
    {
        $app = Factory::getApplication();

        if ($app->isClient('administrator')) {
            $app->enqueueMessage($this->optionsNotLoadedMessage(), 'error');
        }
    }

    /**
     * Refuse the save while the options cannot be loaded, then validate as the list field does.
     *
     * @param mixed $value The value posted for the field.
     * @param string|null $group The field's group.
     * @param Registry|null $input The whole posted data.
     *
     * @return bool|Exception True when valid, the reason otherwise.
     *
     * @since  2.1.0
     */
    public function validate($value, $group = null, ?Registry $input = null)
    {
        if ($this->loadModelRows() === null) {
            return new UnexpectedValueException($this->optionsNotLoadedMessage());
        }

        return parent::validate($value, $group, $input);
    }

    /**
     * The language key of the sentence, which takes the field's title.
     *
     *
     * @since  2.1.0
     */
    protected function notLoadedKey(): string
    {
        return 'COM_ALFA_FIELD_OPTIONS_NOT_LOADED';
    }

    /**
     * The sentence for a field whose options could not be loaded.
     *
     *
     * @since  2.1.0
     */
    private function optionsNotLoadedMessage(): string
    {
        return Text::sprintf($this->notLoadedKey(), $this->getTitle());
    }
}
