<?php

namespace Alfa\Component\Alfa\Administrator\Field;

// use Joomla\CMS\Factory;
// use Joomla\CMS\Form\Field\ListField;
// use Joomla\CMS\HTML\HTMLHelper;
// use Joomla\CMS\Language\Text;
// use Joomla\CMS\Router\Route;
// use Joomla\Component\Menus\Administrator\Helper\MenusHelper;
// use Joomla\Utilities\ArrayHelper;
// use Joomla\CMS\Table\Table;
// use Joomla\CMS\Component\ComponentHelper;
// phpcs:disable PSR1.Files.SideEffects

\defined('_JEXEC') or die;


use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;

// use Joomla\CMS\Date\Date;
use Joomla\CMS\Form\Field\TextField;
use Joomla\CMS\Language\LanguageHelper;
use Joomla\CMS\Language\Multilanguage;
use Joomla\CMS\Language\Text;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Uri\Uri;

class MultilingualTextField extends TextField
{

	protected $type = 'MultilingualText';

	protected function getInput()
	{

		$html = '';
		$languages = LanguageHelper::getLanguages('lang_code'); // Get all installed languages
//		$value = $this->value;
		$defaultName = $this->name;
		$defaultId = $this->id;
//		$flagPath = Uri::root().'media/com_languages/images/'; // Path to the flag images

		// Check if there are multiple languages
		$multilang = count($languages) > 1;// && Multilanguage::isEnabled();

		foreach ($languages as $langCode => $language) {
			// Convert language code from 'el-GR' to 'el_gr'
			$formattedLangCode = str_replace('-', '_', strtolower($langCode));

			// Prepare unique name and id for each language
			$inputId = $defaultId . '_' . $formattedLangCode;
//			$inputName = str_replace('jform['.$defaultName.']', $defaultName . '_' . $formattedLangCode, strtolower($langCode)) ;
			$inputName = $defaultName;

//			print_r($inputName);
//			exit;
			// Input field
			$inputField = $this->render(
				$this->layout,
				[
					'id'             => $inputId,
					'name'           => 'jform[name_'.$formattedLangCode.']',
					'value'          => 'test',
				]
			);

			// Flag Image - Only set the flag image if there are multiple languages
			$flagImage = '';
			if ($multilang) {
				$flagImageInlineStyle = 'position:absolute;right:0;top:0;';
				$flagImage = HTMLHelper::_('image', 'mod_languages/' . $language->image . '.gif', $language->title_native, ['title' => $language->title_native,'style'=>$flagImageInlineStyle], true);
			}

			// Create a div to hold the input and the flag
			$html .= '<div style="position:relative">'.
				$inputField .
				$flagImage
				.'</div>';

		}
		return $html;

	}

}


//			$options     = [
//				'autocomplete'   => $this->autocomplete,
//				'autofocus'      => $this->autofocus,
//				'class'          => $this->class,
//				'description'    => $description,
//				'disabled'       => $this->disabled,
//				'field'          => $this,
//				'group'          => $this->group,
//				'hidden'         => $this->hidden,
//				'hint'           => $this->translateHint ? Text::alt($this->hint, $alt) : $this->hint,
//				'id'             => $this->id,
//				'label'          => $label,
//				'labelclass'     => $this->labelclass,
//				'multiple'       => $this->multiple,
//				'name'           => $this->name,
//				'onchange'       => $this->onchange,
//				'onclick'        => $this->onclick,
//				'pattern'        => $this->pattern,
//				'validationtext' => $this->validationtext,
//				'readonly'       => $this->readonly,
//				'repeat'         => $this->repeat,
//				'required'       => (bool) $this->required,
//				'size'           => $this->size,
//				'spellcheck'     => $this->spellcheck,
//				'validate'       => $this->validate,
//				'value'          => $this->value,
//				'dataAttribute'  => $this->renderDataAttributes(),
//				'dataAttributes' => $this->dataAttributes,
//				'parentclass'    => $this->parentclass,
//			];

//		$html = '';
//		$languages = LanguageHelper::getLanguages('lang_code'); // Get all installed languages
//		$value = $this->value;
//
//		foreach ($languages as $langCode => $language) {
//			// Convert language code from 'el-GR' to 'el_gr'
//			$formattedLangCode = str_replace('-', '_', strtolower($langCode));
//
//			// Get value for this language if already set
//			$langValue = isset($value[$formattedLangCode]) ? $value[$formattedLangCode] : '';
//
//			$inputName = $this->name . '[' . $formattedLangCode . ']'; // Set unique name for each language field
//			$inputId = $this->id . '_' . $formattedLangCode;
//
//			// Optional: Add a label for each language
//			$html .= '<div>';
//			$html .= '<label>' . $language->title . ':</label>';
//			$html .= '<input type="text" name="' . $inputName . '" id="' . $inputId . '" value="' . htmlspecialchars($langValue, ENT_COMPAT, 'UTF-8') . '" />';
//			$html .= '</div>';
//		}
//
//		return $html;