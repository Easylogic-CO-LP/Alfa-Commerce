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
use Alfa\Component\Alfa\Administrator\Service\Document\OrderChargeLines;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;

/**
 * DocumentCorrectsField: which issued sales document a credit note corrects.
 *
 * Not a plain list field, because three things about this picker are not a
 * list field's to express:
 *
 *  - NOTHING IS PRE-SELECTED. The picked document decides which frozen prices
 *    are credited. That is a fiscal attribution the operator makes, never the
 *    screen, so the select opens on a placeholder that is `disabled` (it
 *    cannot be chosen back) and `selected`, so a browser starts there. The
 *    DISABLED IS ON THE OPTION, NEVER ON THE SELECT: a disabled select posts
 *    nothing, and an empty pick has to reach the issuer to be refused by it.
 *    The one exception is the operator's OWN pick on a refused issue's
 *    redisplay, kept while it is still offered.
 *
 *  - THE PLACEHOLDER SAYS WHICH SITUATION THIS IS: pick one, or there is
 *    nothing to pick. When there is nothing, the reason is printed beside the
 *    field rather than left for the operator to infer from an empty list.
 *
 *  - THE OPTIONS ARE ONLY WHAT THE ISSUER WOULD ACCEPT. correctableDocuments()
 *    answers with the issuer's own cap, so the picker never offers a document
 *    whose only outcome is a refused submit.
 *
 *  - AN ORDER THAT CAN TAKE NO CREDIT SAYS WHY. While a credit note on the
 *    order carries a charge line nothing active accounts for, no credit on it
 *    can be sized: nothing is offered, and the one reason printed beside the
 *    picker names those notes, in the words a credit is refused with.
 *
 * @since  1.3.0
 */
class DocumentCorrectsField extends FormField
{
    /**
     * The field type.
     *
     * @var string
     * @since  1.3.0
     */
    protected $type = 'DocumentCorrects';

    /**
     * Render the picker.
     *
     * @return string The field HTML.
     *
     * @since   1.3.0
     */
    protected function getInput(): string
    {
        $orderId = (int) $this->form->getValue('id_order');
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

        $unrecorded = $orderId > 0 ? OrderChargeLines::unrecordedCreditNotes(orderId: $orderId) : [];
        $correctable = $orderId > 0 && $unrecorded === []
            ? DocumentHelper::correctableDocuments(DocumentHelper::getForOrder($orderId))
            : [];

        // A kept draft (a refused issue redisplayed) keeps the operator's own
        // pick while the picker still offers it. That is the one pre-selection:
        // the operator made it, the screen did not.
        $picked = \is_scalar($this->value) ? (int) $this->value : 0;
        $keptPick = $picked > 0 && \in_array($picked, array_map(static fn (object $doc): int => (int) $doc->id, $correctable), true);

        $html = [];
        $html[] = '<select class="form-select" name="' . $this->name . '" id="' . $this->id . '">';
        $html[] = '<option value=""' . ($keptPick ? '' : ' selected') . ' disabled>'
            . Text::_($correctable === []
                ? 'COM_ALFA_DOCUMENTS_CORRECTS_UNAVAILABLE'
                : 'COM_ALFA_DOCUMENTS_CORRECTS_PICK')
            . '</option>';

        foreach ($correctable as $doc) {
            $html[] = '<option value="' . (int) $doc->id . '"' . ($keptPick && (int) $doc->id === $picked ? ' selected' : '') . '>'
                . $escape((string) $doc->number_formatted . ' - '
                    . number_format((float) $doc->total_tax_incl, 2, ',', '.'))
                . '</option>';
        }

        $html[] = '</select>';
        $html[] = '<div class="invalid-feedback">' . Text::_('COM_ALFA_DOCUMENTS_CORRECTS_REQUIRED') . '</div>';

        if ($unrecorded !== []) {
            $html[] = '<p class="form-text text-danger mb-0">'
                . Text::sprintf('COM_ALFA_CREDIT_UNAVAILABLE_UNRECORDED', $escape(implode(', ', array_column($unrecorded, 'number_formatted'))))
                . '</p>';
        } elseif ($correctable === []) {
            $html[] = '<p class="form-text text-danger mb-0">'
                . Text::_('COM_ALFA_DOCUMENTS_CORRECTS_UNAVAILABLE_HINT') . '</p>';
        }

        return implode('', $html);
    }
}
