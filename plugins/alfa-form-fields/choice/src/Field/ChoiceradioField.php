<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Plugin\AlfaFormFields\Choice\Field;

\defined('_JEXEC') or die;

use Alfa\Plugin\AlfaFormFields\Choice\Field\Traits\HasGroupCaptionTrait;
use Joomla\CMS\Form\Field\RadioField;

/**
 * A choice drawn as radios: Joomla's radios, named once by the group's legend.
 *
 * @since  2.1.1
 */
class ChoiceradioField extends RadioField
{
    use HasGroupCaptionTrait;

    /**
     * @var string
     *
     * @since  2.1.1
     */
    protected $type = 'Choiceradio';
}
