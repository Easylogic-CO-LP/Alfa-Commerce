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
use Joomla\CMS\Language\Text;

/**
 * DocumentSeriesField
 *
 * Picker of the document to issue, expressed as a SERIES: a series
 * carries exactly one document type, so choosing the series chooses the
 * document. Per sales type it offers:
 *
 *   - a virtual "<Type> - default series" entry (value = the type slug)
 *     that follows the type's is_default series at issue time; robust
 *     across series reorganisations, the right pick for most shops;
 *   - every published concrete series of that type (value = the id),
 *     labelled "<Type> - <code> (<title>)", for pinning a specific
 *     numbering stream / issuing identity.
 *
 * Only sales types are listed (credits are manual-only, delivery notes
 * have their own status toggle). Used in the order-status auto-issuance
 * rule rows; AutoIssuePolicy::resolveRules consumes the value.
 *
 * @since  1.3.0
 */
class DocumentSeriesField extends ListField
{
    /**
     * Field type id: must match the filename and the XML `type`.
     *
     * @var string
     */
    protected $type = 'DocumentSeries';

    /**
     * Build the option list: per sales type, the virtual default entry
     * then the published concrete series.
     *
     * @return object[]
     *
     * @since  1.3.0
     */
    protected function getOptions(): array
    {
        $db = $this->getDatabase();

        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'doc_type', 'code', 'title']))
            ->from($db->quoteName('#__alfa_document_series'))
            ->where($db->quoteName('state') . ' = 1')
            ->whereIn($db->quoteName('doc_type'), DocumentHelper::salesTypes(), 'string')
            ->order($db->quoteName('code') . ' ASC');
        $db->setQuery(query: $query);
        $rows = $db->loadObjectList() ?: [];

        $options = [];

        foreach (DocumentHelper::salesTypes() as $docType) {
            $typeLabel = DocumentHelper::typeLabel($docType);

            $options[] = HTMLHelper::_(
                'select.option',
                $docType,
                Text::sprintf('COM_ALFA_DOC_RULE_TYPE_DEFAULT', $typeLabel),
            );

            foreach ($rows as $row) {
                if ((string) $row->doc_type !== $docType) {
                    continue;
                }

                $options[] = HTMLHelper::_(
                    'select.option',
                    (string) $row->id,
                    $typeLabel . ' - ' . $row->code . ((string) $row->title !== '' ? ' (' . $row->title . ')' : ''),
                );
            }
        }

        return array_merge(parent::getOptions(), $options);
    }
}
