<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Rule;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\FieldsHelper;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormRule;
use Joomla\CMS\Language\Text;
use Joomla\Registry\Registry;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;
use RuntimeException;
use SimpleXMLElement;

/**
 * One telephone number, validated and stored without decoration.
 *
 * THE SAME RULE WHEREVER A FORM ASKS FOR A NUMBER. The storefront's `tel`
 * checkout field has parsed and normalised numbers for a while; anything else
 * that asked for one (a carrier's sender number in its own settings, a
 * warehouse contact) got a bare text box, so the shop held two kinds of phone
 * number and the code consuming them could not tell which it had. One carrier plugin's
 * settings took the shop's number as free text and the plugin then glued '+30'
 * onto whatever was there, which turned an already-international number into
 * '+30+30...'; that carrier answers a malformed contact number with P405.
 *
 * IT VALIDATES AND DOES NOT TRANSFORM, which is the division Joomla intends.
 * Form::filter() runs first and its output is what the model returns; a rule's
 * answer is a bool and the Registry it is handed is a local copy that is thrown
 * away. Writing a normalised value from in here has nowhere to land, which is
 * exactly why numbers typed with spaces could still reach storage with them.
 * {@see \Alfa\Component\Alfa\Administrator\Filter\TelephoneFilter} produces
 * the canonical E.164 value; this decides whether it is acceptable and refuses
 * it by name when it is not.
 *
 * TWO PATHS, AND NEITHER OF THEM ACCEPTS BLINDLY:
 *
 *   {@see self::testWithLibrary()}  libphonenumber. Knows what a real number
 *                                   looks like in each country, which region a
 *                                   number belongs to, and whether it is a
 *                                   mobile. This is the one that normally runs.
 *   {@see self::testWithPattern()}  Joomla's own tel rule, when the library is
 *                                   absent. Shape only (no country knowledge),
 *                                   so it is weaker, and it says so, but it is
 *                                   still a check.
 *
 * WHY THERE IS A FALLBACK AT ALL, when the file `use`s the class at the top:
 * `use` is an alias and resolves nothing, so an absent class throws only where
 * it is CALLED. libphonenumber ships as a separate library extension, and an
 * install interrupted between the component and its libraries really can lack
 * it. The wrong answer there is to accept every value (a form that silently
 * stops validating is worse than one that validates loosely), so the pattern
 * check takes over and the number is still compacted before it is stored.
 *
 * @since  2.0.0
 */
class TelephoneRule extends FormRule
{
    /**
     * @param SimpleXMLElement $element The field element.
     * @param mixed $value The posted value.
     * @param string|null $group The field group.
     * @param Registry|null $input The whole posted data.
     * @param Form|null $form The form.
     *
     * @return bool
     *
     * @throws RuntimeException With a translated message the person reads.
     *
     * @since  2.0.0
     */
    public function test(SimpleXMLElement $element, $value, $group = null, ?Registry $input = null, ?Form $form = null)
    {
        $value = trim((string) $value);

        // EMPTY IS NOT THIS RULE'S BUSINESS. Whether a number is compulsory is
        // said by `required`, which has its own message; answering here too
        // would refuse an optional field for being optional.
        if ($value === '') {
            return true;
        }

        // WHAT IS TYPED MUST BE MADE OF PHONE, before anything tries to read it.
        //
        // The library is deliberately lenient about SEPARATORS (it keeps the
        // digits and validates those), which is right: '+30 694 768 8592',
        // '+30-694-768-8592' and '+30694(768)8592' are one number, and what is
        // stored is canonical whichever was typed. Even a run of dashes is
        // unambiguous and normalises correctly.
        //
        // A LETTER IS NOT A SEPARATOR, and that leniency swallowed it silently:
        // '+30 694 768 859a2' parsed as VALID and stored '+306947688592': a
        // digit short of what was typed, so a fat-fingered 'a' for '4' produced
        // somebody else's number and told nobody. '...8592 ext 5' lost the
        // extension the same way. So anything that is not a digit, a leading
        // '+' or ordinary punctuation is refused here rather than deleted.
        if (!self::looksTyped($value)) {
            throw new RuntimeException(Text::_('COM_ALFA_TELEPHONE_ERR_STRAY_CHARACTERS'));
        }

        return class_exists(PhoneNumberUtil::class)
            ? $this->testWithLibrary($element, $value, $group, $input)
            : $this->testWithPattern($element, $value, $group, $input);
    }

    /**
     * The real check: parse the number, in a region, and store E.164.
     *
     * @param SimpleXMLElement $element The field element.
     * @param string $value The trimmed posted value.
     * @param string|null $group The field group.
     * @param Registry|null $input The whole posted data.
     *
     *
     * @since  2.0.0
     */
    private function testWithLibrary(SimpleXMLElement $element, string $value, ?string $group, ?Registry $input): bool
    {
        $region = $this->region($element);

        // NO REGION AND NO COUNTRY CODE IS NOT A NUMBER YET. A national number
        // cannot be read without knowing where it is from, and inventing a
        // country to read it against is how "+30" came to be glued onto
        // everything. Say what is missing instead.
        if ($region === '' && !str_starts_with($value, '+')) {
            throw new RuntimeException(Text::_('COM_ALFA_TELEPHONE_ERR_NEEDS_COUNTRY_CODE'));
        }

        $util = PhoneNumberUtil::getInstance();

        try {
            $number = $util->parse($value, $region ?: null);
        } catch (NumberParseException) {
            throw new RuntimeException(Text::_('COM_ALFA_TELEPHONE_ERR_UNPARSEABLE'));
        }

        if (!$util->isValidNumber($number)) {
            throw new RuntimeException(Text::_('COM_ALFA_TELEPHONE_ERR_INVALID'));
        }

        $allowed = $this->listAttribute($element, 'allowed_regions');
        $numberRegion = (string) $util->getRegionCodeForNumber($number);

        if ($allowed !== [] && !\in_array($numberRegion, $allowed, true)) {
            throw new RuntimeException(
                Text::sprintf('COM_ALFA_TELEPHONE_ERR_REGION_NOT_ALLOWED', $numberRegion),
            );
        }

        if (filter_var((string) ($element['require_mobile'] ?? ''), FILTER_VALIDATE_BOOLEAN)) {
            $type = $util->getNumberType($number);

            if (!\in_array($type, [PhoneNumberType::MOBILE, PhoneNumberType::FIXED_LINE_OR_MOBILE], true)) {
                throw new RuntimeException(Text::_('COM_ALFA_TELEPHONE_ERR_NOT_MOBILE'));
            }
        }

        return true;
    }

    /**
     * The fallback: shape only, which is what Joomla's own tel rule checks.
     *
     * Copied from {@see \Joomla\CMS\Form\Rule\TelRule} rather than reached
     * for, so the `plan` attribute keeps working and the messages are ours.
     *
     * It cannot tell a real Greek number from a plausible-looking one, so it is
     * strictly weaker than the library path. It is not, however, "accept
     * anything".
     *
     * @param SimpleXMLElement $element The field element.
     * @param string $value The trimmed posted value.
     * @param string|null $group The field group.
     * @param Registry|null $input The whole posted data.
     *
     *
     * @since  2.0.0
     */
    private function testWithPattern(SimpleXMLElement $element, string $value, ?string $group, ?Registry $input): bool
    {
        $patterns = [
            'NANP' => '/^(?:\+?1[-. ]?)?\(?([2-9][0-8][0-9])\)?[-. ]?([2-9][0-9]{2})[-. ]?([0-9]{4})$/',
            'ITU-T' => '/^\+(?:[0-9] ?){6,14}[0-9]$/',
            'EPP' => '/^\+[0-9]{1,3}\.[0-9]{4,14}(?:x.+)?$/',
        ];

        $plan = match ((string) ($element['plan'] ?? '')) {
            'northamerica', 'us', 'NANP' => 'NANP',
            'IETF', 'EPP' => 'EPP',
            'International', 'int', 'missdn', 'ITU-T' => 'ITU-T',
            default => '',
        };

        $passes = $plan !== ''
            ? (bool) preg_match($patterns[$plan], $value)
            // No plan named: digits only, in the length any real number has.
            : (bool) preg_match('/^[0-9]{7,15}$/', preg_replace('/\D+/', '', $value) ?? '');

        if (!$passes) {
            throw new RuntimeException(Text::_('COM_ALFA_TELEPHONE_ERR_INVALID'));
        }

        return true;
    }

    /**
     * Whether this is made of the things phone numbers are written with.
     *
     * Digits, and the punctuation people separate them with: space, dash, dot,
     * brackets, slash. One '+', and only at the front. Nothing else, so a
     * letter or an 'ext 5' is refused instead of being quietly deleted by a
     * normaliser that only ever looked at the digits.
     *
     * Shape only. Whether those digits are a real number is the library's
     * question, and it is asked next.
     *
     * @param string $value The number as typed.
     *
     *
     * @since  2.0.0
     */
    private static function looksTyped(string $value): bool
    {
        return (bool) preg_match('/^\+?[0-9 ().\/-]+$/', $value);
    }

    /**
     * The region an unprefixed number is read against.
     *
     * IN ORDER, AND NEVER A GUESS. The field's own setting first, because a
     * form that knows its audience says so; then the SHOP's default country,
     * which is a real thing the merchant configured on their checkout's place
     * field; then nothing, which is honest: a national number with no country
     * to read it against is refused rather than assigned to whichever country
     * this software was first written in.
     *
     * @param SimpleXMLElement $element The field element.
     *
     * @return string An alpha-2 code, or '' when the shop has not said.
     *
     * @since  2.0.0
     */
    private function region(SimpleXMLElement $element): string
    {
        $explicit = strtoupper(trim((string) ($element['default_region'] ?? '')));

        return $explicit !== '' ? $explicit : FieldsHelper::shopRegionCode();
    }

    /**
     * A comma-separated attribute as a list of upper-case codes.
     *
     * @param SimpleXMLElement $element The field element.
     * @param string $name The attribute.
     *
     * @return string[]
     *
     * @since  2.0.0
     */
    private function listAttribute(SimpleXMLElement $element, string $name): array
    {
        return array_values(array_filter(array_map(
            static fn (string $code): string => strtoupper(trim($code)),
            explode(',', (string) ($element[$name] ?? '')),
        )));
    }
}
