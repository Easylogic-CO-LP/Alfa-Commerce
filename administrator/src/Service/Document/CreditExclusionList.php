<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Document;

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/**
 * The one list of shipping and fee charges a credit note leaves out.
 *
 * Every screen that confirms or previews a credit prints this list, so the
 * operator reads the same heading and the same rows whichever screen issued
 * the note. A shipping row states, in the same sentence, its share of each
 * shipping discount that was not credited either, because the charge and its
 * discount are one decision, never two. When the document can give back less
 * than crediting such a parcel would, its row states the document's figure
 * and names the document, beside the parcel's share.
 *
 * The input is the calculator's answer as given (CreditResult::exclusions):
 * the entries the operator left out that still hold money after their
 * discount. Nothing is filtered again and no figure is computed here.
 *
 * The output is HTML-safe as returned: every label and the document's number
 * are escaped here, once. A caller prints it as given; escaping it again would
 * print a label holding "&" as "&amp;amp;".
 *
 * @since  1.14.0
 */
final class CreditExclusionList
{
    /**
     * One HTML-safe row per charge left out, in the record's order.
     *
     * @param array $exclusions CreditResult::exclusions: entries with `label`, `remaining_before`,
     *                          `money_left`, `bounded_by_invoice` and `discount_shares`
     *                          [{label, share}], money as decimal strings.
     * @param object $corrected The corrected document's row: its scale formats every figure, so
     *                          every screen prints the same figures, and its number names the
     *                          limit of a charge the document bounds.
     *
     * @return string[]
     *
     * @since   1.14.0
     */
    public static function rows(array $exclusions, object $corrected): array
    {
        $escape = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $money = DocumentMoney::formatter(document: $corrected);
        $rows = [];

        foreach ($exclusions as $entry) {
            $label = $escape((string) $entry['label']);

            // What crediting the charge can still give back, which its invoice
            // may bound below its remaining. A parcel that holds a share of a
            // shipping discount states its remaining and, below, that share:
            // its money left is already net of it. When the invoice bounds that
            // parcel lower still, the row states the bound and names the
            // invoice, since the remaining would promise more than it gives.
            if ($entry['discount_shares'] !== [] && $entry['bounded_by_invoice']) {
                $row = Text::sprintf(
                    'COM_ALFA_DOCUMENTS_CREDIT_NOT_CREDITED_ROW_BOUNDED',
                    $label,
                    $money((float) $entry['money_left']),
                    $escape((string) $corrected->number_formatted),
                );
            } else {
                $available = $entry['discount_shares'] === [] ? $entry['money_left'] : $entry['remaining_before'];
                $row = Text::sprintf(
                    'COM_ALFA_DOCUMENTS_CREDIT_NOT_CREDITED_ROW',
                    $label,
                    $money((float) $available),
                );
            }

            // One clause per shipping discount the charge still holds a share of.
            foreach ($entry['discount_shares'] as $share) {
                $row .= '; ' . Text::sprintf(
                    'COM_ALFA_DOCUMENTS_CREDIT_NOT_CREDITED_SHARE',
                    $escape((string) $share['label']),
                    $money((float) $share['share']),
                );
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * The one notice: the heading and the list, HTML-safe as returned.
     *
     * @param array $exclusions CreditResult::exclusions, as rows() takes it.
     * @param object $corrected The corrected document's row, as rows() takes it.
     *
     * @return string|null Null when nothing was left out.
     *
     * @since   1.14.0
     */
    public static function message(array $exclusions, object $corrected): ?string
    {
        if ($exclusions === []) {
            return null;
        }

        $items = array_map(
            static fn (string $row): string => '<li>' . $row . '</li>',
            self::rows(exclusions: $exclusions, corrected: $corrected),
        );

        return '<p class="mb-1">' . Text::_('COM_ALFA_DOCUMENTS_CREDIT_NOT_CREDITED_HEADING') . '</p>'
            . '<ul class="mb-0">' . implode('', $items) . '</ul>';
    }
}
