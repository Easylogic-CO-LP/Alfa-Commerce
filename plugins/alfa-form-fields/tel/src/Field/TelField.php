<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Plugin\AlfaFormFields\Tel\Field;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\TextField;

defined('_JEXEC') or die;

// Tel input rendered via our own layout so translated hint strings can be
// baked into the markup (rather than passed through Joomla.Text scripts).
// JS toggles which <small data-err="..."> is visible based on validation.
class TelField extends TextField
{
    protected $type = 'Tel';
    protected $layout = 'layouts.tel';

    // Template plg-tmpl override first, plugin tmpl fallback, then parent
    // defaults (template html/layouts, JPATH_ROOT/layouts).
    public function getLayoutPaths(): array
    {
        $template = Factory::getApplication()->getTemplate();

        return array_merge(
            [
                JPATH_THEMES . '/' . $template . '/html/plg_alfa-form-fields_tel',
                JPATH_PLUGINS . '/alfa-form-fields/tel/tmpl',
            ],
            parent::getLayoutPaths(),
        );
    }
}
