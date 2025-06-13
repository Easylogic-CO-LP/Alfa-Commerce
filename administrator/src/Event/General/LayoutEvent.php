<?php

/**
 * Joomla! Content Management System
 *
 * @copyright  (C) 2023 Open Source Matters, Inc. <https://www.joomla.org>
 * @license        GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Administrator\Event\General;

//use Joomla\CMS\Form\Form;
//use Joomla\CMS\Event\Result\ResultAware;
//use Joomla\CMS\Event\Result\ResultAwareInterface;
//use Joomla\CMS\Event\Result\ResultTypeStringAware;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Class for CustomFields events
 *
 * @since  5.0.0
 */
class LayoutEvent extends GeneralEvent //implements ResultAwareInterface
{
//	use ResultAware;
//	use ResultTypeStringAware;

	/**
	 * The argument names, in order expected by legacy plugins.
	 *
	 * @var array
	 *
	 * @since      5.0.0
	 * @deprecated 5.0 will be removed in 6.0
	 */
	// protected $legacyArgumentsOrder = ['subject', 'fieldset', 'form'];

	/**
	 * Constructor.
	 *
	 * @param   string  $name       The event name.
	 * @param   array   $arguments  The event arguments.
	 *
	 * @throws  \BadMethodCallException
	 *
	 * @since   5.0.0
	 */
	public function __construct($name, array $arguments = [])
	{
		parent::__construct($name, $arguments);

		if (!\array_key_exists('method', $this->arguments))
		{
			throw new \BadMethodCallException("Argument 'method' of event {$name} is required but has not been provided");
		}
	}

	/**
	 * Setter for the fieldset argument.
	 *
	 * @param   $value  The value to set
	 *
	 * @return
	 *
	 * @since  5.0.0
	 */
	protected function onSetMethod($value)
	{
		return $value;
	}


	/**
	 * Getter for the fieldset.
	 *
	 * @return
	 *
	 * @since  5.0.0
	 */
	public function getMethod()
	{
		return $this->arguments['method'];
	}

	/**
	 * Getter for the layout. If not set, return 'default_product_view'.
	 *
	 * @return  string
	 *
	 * @since  5.0.0
	 */
	public function getLayoutPluginType(): string
	{
		return $this->arguments['layoutPluginType'] ?? '';
	}

	/**
	 * Setter for the layout argument.
	 *
	 * @param   string  $value  The layout value to set
	 *
	 *
	 * @since  5.0.0
	 */
	public function onSetLayoutPluginType($value): void
	{
		$this->setLayoutPluginType($value);
	}

	public function setLayoutPluginType($value): void
	{
		$this->arguments['layoutPluginType'] = $value;
	}

	/**
	 * Getter for the layout. If not set, return 'default_product_view'.
	 *
	 * @return  string
	 *
	 * @since  5.0.0
	 */
	public function getLayoutPluginName(): string
	{
		return $this->arguments['layoutPluginName'] ?? '';
	}

	/**
	 * Setter for the layout argument.
	 *
	 * @param   string  $value  The layout value to set
	 *
	 *
	 * @since  5.0.0
	 */
	public function onSetLayoutPluginName($value): void
	{
		$this->setLayoutPluginName($value);
	}

	public function setLayoutPluginName($value): void
	{
		$this->arguments['layoutPluginName'] = $value;
	}

	/**
	 * Getter for the layout. If not set, return 'default_product_view'.
	 *
	 * @return  string
	 *
	 * @since  5.0.0
	 */
	public function getLayout(): string
	{
        // TODO: Why default_product_view and not just an empty string?
		return $this->arguments['layout'] ?? '';
	}

	/**
	 * Setter for the layout argument.
	 *
	 * @param   string  $value  The layout value to set
	 *
	 *
	 * @since  5.0.0
	 */
	public function onSetLayout($value): void
	{
		$this->setLayout($value);
	}

	public function setLayout($value): void
	{
		$this->arguments['layout'] = $value;
	}

	/**
	 * Getter for the layout. If not set, return 'default_product_view'.
	 *
	 * @return  array
	 *
	 * @since  5.0.0
	 */
	public function getLayoutData(): array
	{
		return $this->arguments['layoutData'] ?? [];
	}

	/**
	 * Setter for the layout argument.
	 *
	 * @param   string  $value  The layout value to set
	 *
	 *
	 * @since  5.0.0
	 */
	public function onSetLayoutData($value): void
	{
		$this->onSetLayoutData($value);
	}

	public function setLayoutData($value): void
	{
		$this->arguments['layoutData'] = $value;
	}

}
