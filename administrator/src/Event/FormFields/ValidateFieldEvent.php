<?php

/**
 * Alfa Commerce
 *
 * @copyright  (C) 2023 Open Source Matters, Inc. <https://www.joomla.org>
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\FormFields;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Class for CustomFields events
 *
 * @since  5.0.0
 */
class ValidateFieldEvent extends FormFieldsEvent
{
    public function getForm()
    {
        return $this->getSubject();
    }

    public function getField()
    {
        return $this->arguments['field_'];
    }

    public function setField($field)
    {
        $this->setArgument('field_', $field);
    }

    public function getValid()
    {
        return $this->arguments['valid_'];
    }

    public function setValid($valid)
    {
        $this->setArgument('valid_', $valid);
    }
}
