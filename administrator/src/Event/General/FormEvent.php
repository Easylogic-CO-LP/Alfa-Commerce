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

namespace Alfa\Component\Alfa\Administrator\Event\General;

use BadMethodCallException;
use Joomla\CMS\Event\AbstractImmutableEvent;
use Joomla\CMS\Form\Form;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Class for Model Form event
 *
 * @since  5.0.0
 */
abstract class FormEvent extends AbstractImmutableEvent
{
    /**
     * Constructor.
     *
     * @param string $name The event name.
     * @param array $arguments The event arguments.
     *
     * @throws BadMethodCallException
     *
     * @since   5.0.0
     */
    public function __construct($name, array $arguments = [])
    {
        parent::__construct($name, $arguments);

        if (!\array_key_exists('subject', $this->arguments)) {
            throw new BadMethodCallException("Argument 'subject' of event {$name} is required but has not been provided");
        }

        if (!\array_key_exists('data', $this->arguments)) {
            throw new BadMethodCallException("Argument 'data' of event {$name} is required but has not been provided");
        }
    }

    //	TODO: CHECK EVENTS HERE
    /**
     * Setter for the context argument.
     *
     * @param string $value The value to set
     *
     *
     * @since  5.0.0
     */
    protected function onSetContext(string $value): string
    {
        return $value;
    }

    /**
     * Getter for the context argument.
     *
     *
     * @since  5.0.0
     */
    public function getContext(): string
    {
        return $this->arguments['context'];
    }

    /**
     * Setter for the subject argument.
     *
     * @param Form $value The value to set
     *
     *
     * @since  5.0.0
     */
    protected function onSetSubject(Form $value): Form
    {
        return $value;
    }

    public function setForm($form)
    {
        $this->arguments['subject'] = $form;
    }

    /**
     * Setter for the data argument.
     *
     * @param object|array $value The value to set
     *
     *
     * @since  5.0.0
     */
    protected function onSetData(object|array $value): object|array
    {
        return $value;
    }

    /**
     * Getter for the form.
     *
     *
     * @since  5.0.0
     */
    public function getForm(): Form
    {
        return $this->arguments['subject'];
    }

    /**
     * Getter for the data.
     *
     *
     * @since  5.0.0
     */
    public function getData(): object|array
    {
        return $this->arguments['data'];
    }

    public function setData($data)
    {
        $this->arguments['data'] = $data;
    }
}
