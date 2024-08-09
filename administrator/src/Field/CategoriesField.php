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
use Joomla\CMS\Language\Text;
use Joomla\CMS\HTML\HTMLHelper;

class CategoriesField extends ListField
{

	protected $type = 'categories';

	protected $options = [];

	protected function getOptions()
	{
		$removeCurrent = $this->element['removeCurrent']=='true' ? true : false;
		$showPath      = $this->element['showPath']=='true' ? true : false;

		$this->options = parent::getOptions();

		$app       = Factory::getApplication();
		$component = $app->bootComponent('com_alfa');
		$factory   = $component->getMVCFactory();
		$model     = $factory->createModel('Categories', 'Administrator');
		$model->setState('filter.state', '*');

		//		TODO: set filtering by name ( is not setting )
		//	    $app->input->set('list.ordering', 'name');
		//	    $app->input->set('list.direction', 'DESC');
		//	    $model->setState('list.ordering', 'name');
		//	    $model->setState('list.direction', 'ASC');

		$categories = $model->getItems();

		foreach ($categories as $category)
		{
			$this->options['cat-' . $category->id] =
				array('value' => $category->id,
				      'text'  => ($showPath
					                ?$category->path
					                :str_repeat('-', $category->depth).$category->name
				                )
	                );
	    }

		if ($removeCurrent)
		{
			$idToRemove = $this->form->getData()->get('id');
			unset($this->options['cat-' . $idToRemove]);
		}

		return $this->options;

	}

}
