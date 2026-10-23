<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Field\Traits\HasCaptionLabelTrait;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Layout\LayoutHelper;

/**
 * Options > Downloads: the download store's direct-download protection, with "Check again".
 *
 * A status, not a setting: it renders no input, so the Options save stores nothing for it.
 *
 * @since  2.0.0
 */
class DownloadprotectionField extends FormField
{
    use HasCaptionLabelTrait;

    /**
     * @var string
     */
    public $type = 'Downloadprotection';

    /**
     * The status, with the states that need no warning shown too.
     *
     * @return string
     *
     * @since  2.0.0
     */
    protected function getInput()
    {
        return LayoutHelper::render('downloads.protection', ['always' => true], JPATH_ADMINISTRATOR . '/components/com_alfa/layouts');
    }
}
