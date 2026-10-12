<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Filter;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\FieldsHelper;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormFilterInterface;
use Joomla\Registry\Registry;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use SimpleXMLElement;

/**
 * Turns a typed telephone number into the one canonical form that is stored.
 *
 * A FILTER, BECAUSE FILTERING IS THE STEP THAT TRANSFORMS. Joomla runs
 * Form::filter() and then Form::validate(), and the model returns the FILTERED
 * data: a rule's return value is a bool and the Registry it is handed is a
 * local copy that is thrown away. The checkout's tel rule writes its normalised
 * value into that Registry, which is why a number typed with spaces can still
 * reach storage with them: the write had nowhere to land.
 *
 * So the split is the one Joomla intends. This produces the value; the rule
 * beside it decides whether the value is acceptable and refuses it by name if
 * it is not. Neither does the other's job.
 *
 * WHAT IS STORED IS ALWAYS COMPACT: E.164 where the library can give it, a
 * leading '+' and digits otherwise. A stored number is data other systems dial,
 * compare and post to an API, and "+30 691 234 5678" is several strings
 * depending on who typed it. Formatting for a human belongs where it is shown.
 *
 * IT NEVER REJECTS. A filter that dropped a bad value would leave the rule with
 * nothing to complain about and the person with an empty box and no reason, so
 * anything it cannot make sense of is passed through untouched for the rule to
 * refuse by name.
 *
 * @since  1.10.0
 */
class TelephoneFilter implements FormFilterInterface
{
    /**
     * @param SimpleXMLElement $element The field element.
     * @param mixed $value The posted value.
     * @param string|null $group The field group.
     * @param Registry|null $input The whole posted data.
     * @param Form|null $form The form.
     *
     * @return string The canonical number, or the value untouched when it is
     *                not one; the rule answers for that.
     *
     * @since  1.10.0
     */
    public function filter(SimpleXMLElement $element, $value, $group = null, ?Registry $input = null, ?Form $form = null)
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        // Anything with a stray character in it is left exactly as typed: the
        // rule refuses it and the person sees what they wrote, rather than a
        // silently repaired number that is a digit different from theirs.
        if (!preg_match('/^\+?[0-9 ().\/-]+$/', $value)) {
            return $value;
        }

        if (class_exists(PhoneNumberUtil::class)) {
            $canonical = $this->viaLibrary($element, $value);

            if ($canonical !== null) {
                return $canonical;
            }
        }

        return self::compact($value);
    }

    /**
     * E.164 from libphonenumber, or null when it cannot read the number.
     *
     * @param SimpleXMLElement $element The field element.
     * @param string $value The typed number.
     *
     *
     * @since  1.10.0
     */
    private function viaLibrary(SimpleXMLElement $element, string $value): ?string
    {
        $region = strtoupper(trim((string) ($element['default_region'] ?? ''))) ?: FieldsHelper::shopRegionCode();

        // No region and no country code: not a number yet, and inventing a
        // country to read it against is how '+30' came to be glued onto
        // everything. Leave it for the rule to say so.
        if ($region === '' && !str_starts_with($value, '+')) {
            return null;
        }

        $util = PhoneNumberUtil::getInstance();

        try {
            $number = $util->parse($value, $region ?: null);
        } catch (NumberParseException) {
            return null;
        }

        return $util->isValidNumber($number)
            ? $util->format($number, self::storeFormat($element))
            : null;
    }

    /**
     * The shape the number is stored in.
     *
     * FOUR ARE POSSIBLE AND ONE IS RIGHT. `store_format` on the field accepts:
     *
     *   e164           +306947688592          the default, and what to use
     *   international  +30 694 768 8592       readable, still carries the country
     *   national       694 768 8592           NO COUNTRY: only meaningful to a
     *                                         reader who already knows which one
     *   rfc3966        tel:+30-694-768-8592   for a `tel:` link
     *
     * E.164 is the default because a stored number is data: something dials it,
     * compares it, posts it to a carrier's API. The other three are formatting,
     * and formatting belongs where the number is shown: a shop that stores
     * `national` has thrown the country away and cannot get it back, and two
     * customers in different countries can then collide on the same string.
     *
     * The option exists because an external system occasionally demands one of
     * the others on the way in. Nothing in this shop asks for that.
     *
     * @param SimpleXMLElement $element The field element.
     *
     * @return PhoneNumberFormat The library's own format enum.
     *
     * @since  1.10.0
     */
    private static function storeFormat(SimpleXMLElement $element): PhoneNumberFormat
    {
        return match (strtolower(trim((string) ($element['store_format'] ?? '')))) {
            'international' => PhoneNumberFormat::INTERNATIONAL,
            'national' => PhoneNumberFormat::NATIONAL,
            'rfc3966' => PhoneNumberFormat::RFC3966,
            default => PhoneNumberFormat::E164,
        };
    }

    /**
     * Digits, and the '+' when it had one.
     *
     * @param string $value The typed number.
     *
     *
     * @since  1.10.0
     */
    private static function compact(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return str_starts_with($value, '+') ? '+' . $digits : $digits;
    }
}
