<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\DocumentHelper;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;

/**
 * DocumentTypeField
 *
 * Registry-driven document type picker: options are the
 * VISIBLE `#__alfa_document_types` rows in registry order, values are
 * the immutable aliases, labels resolve via DocumentHelper::typeLabel().
 * Static XML options can precede these (e.g. an "- any -" filter entry).
 *
 * @since  2.0.0
 */
class DocumentTypeField extends ListField
{
    /**
     * Field type id: must match the filename and the XML `type`.
     *
     * @var string
     */
    protected $type = 'DocumentType';

    /**
     * Build the option list from the registry.
     *
     * @return object[]
     *
     * @since  2.0.0
     */
    protected function getOptions(): array
    {
        $options = [];

        foreach (DocumentHelper::types() as $alias) {
            $options[] = HTMLHelper::_('select.option', $alias, DocumentHelper::typeLabel($alias));
        }

        return array_merge(parent::getOptions(), $options);
    }
}
