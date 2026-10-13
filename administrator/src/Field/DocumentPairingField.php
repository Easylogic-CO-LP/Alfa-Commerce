<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\DocumentHelper;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;

/**
 * The sales types a credit document type corrects.
 *
 * Options are the sales document types in registry order, valued by alias and
 * labelled by DocumentHelper::typeLabel(). A multiple list with nothing chosen
 * posts nothing, so an empty value of the same name is printed first: a list
 * emptied on the form then saves as "corrects none" instead of being left as
 * it was.
 *
 * @since  2.0.0
 */
class DocumentPairingField extends ListField
{
    /**
     * Field type id: must match the filename and the XML `type`.
     *
     * @var string
     * @since 2.0.0
     */
    protected $type = 'DocumentPairing';

    /**
     * The sales document types, by alias.
     *
     * @return object[]
     *
     * @since  2.0.0
     */
    protected function getOptions(): array
    {
        $options = [];

        foreach (DocumentHelper::salesTypes() as $alias) {
            $options[] = HTMLHelper::_('select.option', $alias, DocumentHelper::typeLabel($alias));
        }

        return array_merge(parent::getOptions(), $options);
    }

    /**
     * The list, after an empty value of the same name.
     *
     *
     * @since  2.0.0
     */
    protected function getInput(): string
    {
        return '<input type="hidden" name="' . htmlspecialchars($this->name, ENT_QUOTES, 'UTF-8') . '" value="">'
            . parent::getInput();
    }
}
