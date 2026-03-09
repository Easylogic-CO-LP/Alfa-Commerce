<?php
namespace Alfa\Component\Alfa\Site\Service\Pricing;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;

class DateRangeHelper
{
	public function getActiveCondition(string $startField, string $endField): string
	{
		$db = Factory::getContainer()->get('DatabaseDriver');

		return sprintf(
			"IFNULL(NOW() >= %s OR %s = '0000-00-00 00:00:00', 1) = 1 " .
			"AND IFNULL(%s != '0000-00-00 00:00:00' AND NOW() > %s, 0) = 0",
			$db->qn($startField),
			$db->qn($startField),
			$db->qn($endField),
			$db->qn($endField)
		);
	}
}