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
use Alfa\Component\Alfa\Administrator\Service\Document\DocumentIssuer;
use Alfa\Component\Alfa\Administrator\Service\Document\OrderChargeLines;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use RuntimeException;

/**
 * DocumentCreditBuyerField: who a credit note is made out to, shown, never entered.
 *
 * A credit is made out to the buyer of the document it corrects, exactly as
 * that document froze it: the controller reads the
 * buyer from there and ignores any buyer field posted with a credit, and the
 * issuer refuses any other. So the credit types show no buyer INPUTS; they show
 * this, one read-only block per document the Corrects picker offers, all seven
 * frozen columns: the country and the address included, which are carried,
 * never editable.
 *
 * The blocks are server-rendered and the modal's script only shows the one the
 * picker names (the same pattern as the charge groups). They hold no form
 * control at all, so nothing in them can post, visible or not.
 *
 * @since  1.14.0
 */
class DocumentCreditBuyerField extends FormField
{
    /**
     * The field type.
     *
     * @var string
     * @since  1.14.0
     */
    protected $type = 'DocumentCreditBuyer';

    /**
     * Render the blocks.
     *
     * @return string The field HTML, or nothing when the order has nothing a credit may correct
     *                or can take no credit at all.
     *
     * @since   1.14.0
     */
    protected function getInput(): string
    {
        $orderId = (int) $this->form->getValue('id_order');

        if ($orderId <= 0) {
            return '';
        }

        // An order on which a credit note carries a charge line nothing active
        // accounts for can take no credit, so it shows no credit's buyer.
        if (OrderChargeLines::unrecordedCreditNotes(orderId: $orderId) !== []) {
            return '';
        }

        $correctable = DocumentHelper::correctableDocuments(DocumentHelper::getForOrder($orderId));

        if ($correctable === []) {
            return '';
        }

        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        // A kept draft (a refused issue redisplayed) names its pick; the
        // script shows the same block on its first sync.
        $picked = (int) $this->form->getValue('id_corrects');

        $html = ['<div id="issue_credit_buyer">'];
        $html[] = '<p class="small text-muted mb-1 issue-credit-buyer" data-corrects=""'
            . ($picked > 0 ? ' hidden' : '') . '>' . Text::_('COM_ALFA_DOCUMENTS_CREDIT_BUYER_PICK') . '</p>';

        foreach ($correctable as $document) {
            $html[] = '<div class="issue-credit-buyer" data-corrects="' . (int) $document->id . '"'
                . ((int) $document->id === $picked ? '' : ' hidden') . '>';

            try {
                $rows = $this->rows(DocumentIssuer::buyerBlock(DocumentIssuer::frozenBuyer($document)));
            } catch (RuntimeException $e) {
                // A stored buyer that cannot be read is said, not guessed at;
                // the issuer refuses a credit against it in the same words.
                $html[] = '<p class="small text-danger mb-1">' . $escape($e->getMessage()) . '</p></div>';

                continue;
            }

            if ($rows === []) {
                $html[] = '<p class="small text-muted mb-1">' . Text::_('COM_ALFA_DOCUMENTS_CREDIT_BUYER_NONE') . '</p>';
            } else {
                $html[] = '<dl class="row small mb-1">';

                foreach ($rows as $label => $value) {
                    $html[] = '<dt class="col-sm-3">' . Text::_($label) . '</dt>'
                        . '<dd class="col-sm-9">' . nl2br($escape($value)) . '</dd>';
                }

                $html[] = '</dl>';
            }

            $html[] = '</div>';
        }

        $html[] = '<p class="small text-muted mb-0">' . Text::_('COM_ALFA_DOCUMENTS_CREDIT_BUYER_FROZEN') . '</p>';
        $html[] = '</div>';

        return implode('', $html);
    }

    /**
     * One frozen buyer as labelled rows, the empty columns left out.
     *
     * @param array $block The buyer as DocumentIssuer::buyerBlock() normalises it.
     *
     * @return array<string, string> Language key => value.
     *
     * @since   1.14.0
     */
    private function rows(array $block): array
    {
        // The address in its stored order ({street, number, city, zip, region,
        // country}, or the free text a printed document shows), as the
        // replacement modal shows a source's.
        $address = json_decode((string) ($block['buyer_address'] ?? ''), true);

        return array_filter([
            'COM_ALFA_DOCUMENTS_FIELD_BUYER_NAME' => $block['buyer_name'],
            'COM_ALFA_DOCUMENTS_FIELD_BUYER_VAT' => $block['buyer_vat_number'],
            'COM_ALFA_DOCUMENTS_FIELD_BUYER_TAX_OFFICE' => $block['buyer_tax_office'],
            'COM_ALFA_DOCUMENTS_FIELD_BUYER_ACTIVITY' => $block['buyer_activity'],
            'JGLOBAL_EMAIL' => $block['buyer_email'],
            'COM_ALFA_REPLACEMENT_BUYER_COUNTRY' => $block['buyer_country'],
            'COM_ALFA_DOC_PRINT_ADDRESS' => \is_array($address) ? implode(', ', array_filter(
                array_map(static fn ($part): string => \is_scalar($part) ? trim((string) $part) : '', $address),
                static fn (string $part): bool => $part !== '',
            )) : '',
        ], static fn (string $value): bool => $value !== '');
    }

    /**
     * No label row: the buyer heading above it carries the group's name.
     *
     * @return string Empty.
     *
     * @since   1.14.0
     */
    protected function getLabel(): string
    {
        return '';
    }
}
