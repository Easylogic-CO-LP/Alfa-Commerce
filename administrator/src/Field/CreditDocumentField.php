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
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\Language\Text;

/**
 * The credit note a refund settles: the order's issued credit documents.
 *
 * This is the missing half of the refund ATTRIBUTION primitive. The column
 * `#__alfa_order_payments.id_document` and PaymentLedger's `->document()`
 * builder already existed, but nothing ever wrote them and the form field was
 * readonly, so "how much of credit note #N was actually refunded" had no
 * answer at all. Consumers were pushed towards the order-wide refund total,
 * which reports every credit note on an order as settled the moment any one
 * of them is: money that never moved, shown as returned.
 *
 * Scoped to the order being edited: a refund can only ever settle a credit
 * note of its own order, so offering anything else would invite exactly the
 * cross-order attribution this exists to prevent. Voided notes are excluded:
 * they settle nothing.
 *
 * @since  2.0.0
 */
class CreditDocumentField extends ListField
{
    /**
     * The form field type.
     *
     * @var string
     * @since  2.0.0
     */
    protected $type = 'CreditDocument';

    /**
     * The order's issued credit notes, newest first.
     *
     * @return array
     *
     * @since  2.0.0
     */
    protected function getOptions()
    {
        $options = parent::getOptions();

        // The order id travels on the form's own data (the payments subform
        // lives on the order edit screen), falling back to the request.
        $orderId = (int) ($this->form->getValue('id_order') ?: 0);

        if ($orderId <= 0) {
            $orderId = (int) Factory::getApplication()->getInput()->getInt('id', 0);
        }

        if ($orderId <= 0) {
            return $options;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $creditTypes = DocumentHelper::creditTypes();

        if ($creditTypes === []) {
            return $options;
        }

        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('id'),
                $db->quoteName('number_formatted'),
                $db->quoteName('total_tax_incl'),
                $db->quoteName('currency_code'),
            ])
            ->from($db->quoteName('#__alfa_documents'))
            ->where($db->quoteName('id_order') . ' = ' . $orderId)
            ->where($db->quoteName('status') . ' = ' . DocumentHelper::STATUS_ISSUED)
            ->whereIn($db->quoteName('doc_type'), $creditTypes, \Joomla\Database\ParameterType::STRING)
            ->order($db->quoteName('id') . ' DESC');
        $db->setQuery($query);

        foreach ($db->loadObjectList() ?: [] as $document) {
            $options[] = (object) [
                'value' => (string) $document->id,
                'text' => sprintf(
                    '%s - %s %s',
                    (string) $document->number_formatted,
                    number_format((float) $document->total_tax_incl, 2, ',', '.'),
                    (string) $document->currency_code,
                ),
            ];
        }

        return $this->withStoredValue($options, $orderId);
    }

    /**
     * Keep the SAVED attribution selectable even when it is not in the list.
     *
     * A select cannot render a value it has no option for: the browser falls
     * back to the first option, and the next save (of anything at all, a
     * typo in the note) silently rewrites the refund's attribution. The
     * value drops out of the list the moment its note is voided, which is
     * exactly when the historical link matters most, so the stored document
     * is re-injected and labelled for what it is.
     *
     * @param array $options Options built from the live list.
     * @param int $orderId The order being edited.
     *
     *
     * @since  2.0.0
     */
    private function withStoredValue(array $options, int $orderId): array
    {
        $stored = (int) $this->value;

        if ($stored <= 0) {
            return $options;
        }

        foreach ($options as $option) {
            if ((string) $option->value === (string) $stored) {
                return $options;
            }
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('number_formatted'),
                $db->quoteName('total_tax_incl'),
                $db->quoteName('currency_code'),
                $db->quoteName('status'),
                $db->quoteName('id_order'),
            ])
            ->from($db->quoteName('#__alfa_documents'))
            ->where($db->quoteName('id') . ' = ' . $stored);
        $db->setQuery($query);
        $document = $db->loadObject();

        // Still scoped to this order: a stored id pointing somewhere else is
        // corrupt data, and echoing it back would make the field a
        // cross-order attribution leak.
        if ($document === null || (int) $document->id_order !== $orderId) {
            return $options;
        }

        $options[] = (object) [
            'value' => (string) $stored,
            'text' => sprintf(
                '%s - %s %s%s',
                (string) $document->number_formatted,
                number_format((float) $document->total_tax_incl, 2, ',', '.'),
                (string) $document->currency_code,
                (int) $document->status === DocumentHelper::STATUS_VOIDED
                    ? ', ' . Text::_('COM_ALFA_REFUNDED_DOCUMENT_VOIDED')
                    : '',
            ),
        ];

        return $options;
    }
}
