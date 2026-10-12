<?php

namespace Alfa\Plugin\AlfaFormFields\Tel\Rule;

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

defined('_JEXEC') or die;

// Resolved by Joomla when validate="alfatel" on the field node.
class AlfatelRule extends FormRule
{
    public function test(SimpleXMLElement $element, $value, $group = null, ?Registry $input = null, ?Form $form = null)
    {
        // Empty + not required -> OK. Joomla handles "required" separately.
        if ($value === '' || $value === null) {
            return true;
        }

        // THE SAME REGION THE FILTER READS AGAINST: the field's, else the
        // shop's. Assuming Greece here while the filter assumed nothing let a
        // national number pass this rule and reach storage without its
        // country, the exact number a carrier then refuses. No region and no
        // country code is not a number yet, and is refused as one.
        $defaultRegion = strtoupper(trim((string) ($element['default_region'] ?? ''))) ?: FieldsHelper::shopRegionCode();
        $allowedRegions = array_filter(array_map('trim', explode(',', (string) $element['allowed_regions'])));
        $requireMobile = filter_var((string) $element['require_mobile'], FILTER_VALIDATE_BOOLEAN);

        if ($defaultRegion === '' && !str_starts_with(trim((string) $value), '+')) {
            throw new RuntimeException(Text::_('PLG_ALFA_FORM_FIELDS_TEL_ERR_UNPARSEABLE'));
        }

        $util = PhoneNumberUtil::getInstance();

        try {
            $number = $util->parse((string) $value, $defaultRegion ?: null);
        } catch (NumberParseException) {
            throw new RuntimeException(Text::_('PLG_ALFA_FORM_FIELDS_TEL_ERR_UNPARSEABLE'));
        }

        if (!$util->isValidNumber($number)) {
            throw new RuntimeException(Text::_('PLG_ALFA_FORM_FIELDS_TEL_ERR_INVALID'));
        }

        $region = $util->getRegionCodeForNumber($number);

        if ($allowedRegions && !\in_array($region, $allowedRegions, true)) {
            throw new RuntimeException(Text::sprintf('PLG_ALFA_FORM_FIELDS_TEL_ERR_REGION_NOT_ALLOWED', $region));
        }

        if ($requireMobile) {
            $type = $util->getNumberType($number);
            if (!\in_array($type, [PhoneNumberType::MOBILE, PhoneNumberType::FIXED_LINE_OR_MOBILE], true)) {
                throw new RuntimeException(Text::_('PLG_ALFA_FORM_FIELDS_TEL_ERR_NOT_MOBILE'));
            }
        }

        // STORAGE IS THE FILTER'S JOB. This Registry is Form::validate()'s own
        // copy and nothing reads it back, so a value written here never reached
        // a row. Tel::prepareDom() puts the component's telephone filter on the
        // node, and that is what turns the typed number into E.164.
        return true;
    }
}
