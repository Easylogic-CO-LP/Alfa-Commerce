<?php

namespace Alfa\Plugin\AlfaFormFields\Tel\Rule;

use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormRule;
use Joomla\CMS\Language\Text;
use Joomla\Registry\Registry;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
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
        // Empty + not required → OK. Joomla handles "required" separately.
        if ($value === '' || $value === null) {
            return true;
        }

        // Defaults come from the plugin params; per-field params override via the node.
        $defaultRegion = (string) ($element['default_region'] ?: 'GR');
        $allowedRegions = array_filter(array_map('trim', explode(',', (string) $element['allowed_regions'])));
        $requireMobile = filter_var((string) $element['require_mobile'], FILTER_VALIDATE_BOOLEAN);

        $util = PhoneNumberUtil::getInstance();

        try {
            $number = $util->parse((string) $value, $defaultRegion);
        } catch (NumberParseException $e) {
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

        // Always normalise to E.164 for storage. Display formatting happens at
        // render time via the `display_format` param — storage stays canonical.
        if ($input !== null && $group !== null) {
            $input->set($group . '.' . (string) $element['name'], $util->format($number, PhoneNumberFormat::E164));
        }

        return true;
    }
}
