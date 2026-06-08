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
    /**
     * Get the form being validated (the event subject).
     *
     * @return mixed The form instance
     *
     * @since  5.0.0
     */
    public function getForm()
    {
        return $this->getSubject();
    }

    /**
     * Get the field definition being validated.
     *
     * @return mixed The field object
     *
     * @since  5.0.0
     */
    public function getField()
    {
        return $this->arguments['field_'];
    }

    /**
     * Set the field definition being validated.
     *
     * @param mixed $field The field object
     *
     * @return void
     *
     * @since  5.0.0
     */
    public function setField($field)
    {
        $this->setArgument('field_', $field);
    }

    /**
     * Get the current validation result for the field.
     *
     * @return mixed Truthy when the field value is valid
     *
     * @since  5.0.0
     */
    public function getValid()
    {
        return $this->arguments['valid_'];
    }

    /**
     * Set the validation result for the field.
     *
     * @param mixed $valid Truthy when the field value is valid
     *
     * @return void
     *
     * @since  5.0.0
     */
    public function setValid($valid)
    {
        $this->setArgument('valid_', $valid);
    }
}
