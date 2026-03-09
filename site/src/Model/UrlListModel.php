<?php
/**
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Site\Model;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\ListModel;

/**
 * URL-based List Model
 *
 * URL is the single source of truth for all state.
 * No session persistence - clean, predictable behavior.
 *
 * @since  1.0.0
 */
abstract class UrlListModel extends ListModel
{
	/**
	 * Application instance
	 *
	 * @var \Joomla\CMS\Application\CMSApplication
	 */
	protected $app;

	/**
	 * Cached defaults from XML form
	 *
	 * @var array|null
	 */
	protected ?array $defaults = null;

	/**
	 * Fallback defaults if not defined in XML
	 *
	 * @var array
	 */
	protected array $fallbackDefaults = [
		'filter' => [],
		'list' => [
			'fullordering' => 'a.id DESC',
			'limit' => 25,
		],
	];

	/**
	 * Constructor
	 *
	 * @param array $config Configuration array
	 */
	public function __construct($config = [])
	{
		parent::__construct($config);
		$this->app = Factory::getApplication();

		// Auto-set filterFormName if not defined
		if (empty($this->filterFormName)) {
			$this->filterFormName = $this->getDefaultFilterFormName();
		}
	}

	/**
	 * Get default filter form name based on model class name
	 *
	 * Examples:
	 * - Alfa\Component\Alfa\Site\Model\ItemsModel -> filter_items
	 * - Alfa\Component\Alfa\Site\Model\CategoriesModel -> filter_categories
	 *
	 * @return string|null
	 */
	protected function getDefaultFilterFormName(): ?string
	{
		$classNameParts = explode('Model', get_called_class());

		if (count($classNameParts) >= 2) {
			return 'filter_' . str_replace('\\', '', strtolower($classNameParts[1]));
		}

		return null;
	}

	// =========================================================================
	// FORM DATA BINDING
	// =========================================================================

	/**
	 * Get data for filter form binding
	 *
	 * @return array
	 */
	protected function loadFormData(): array
	{
		if (empty($this->filterFormName)) {
			return [];
		}

		$this->getState();

		$data = [
			'filter' => [],
			'list' => [],
		];

		$filters = $this->app->getInput()->get('filter', [], 'array');
		foreach (array_keys($filters) as $name) {
			$data['filter'][$name] = $this->getState('filter.' . $name);
		}

		$data['list'] = [
			'fullordering' => $this->getState('list.ordering') . ' ' . $this->getState('list.direction'),
			'limit' => $this->getState('list.limit'),
		];

		return $data;
	}

	public function getFilterForm($data = array(), $loadData = true)
	{
		\Joomla\CMS\Form\Form::addFormPath(JPATH_SITE . '/components/com_alfa/forms');
		return parent::getFilterForm($data, $loadData);
	}

	/**
	 * Get active filters for display (badges/clear buttons)
	 *
	 * @return array
	 */
	public function getActiveFilters(): array
	{
		if (empty($this->filterFormName)) {
			return [];
		}

		$activeFilters = [];
		$filters = $this->app->getInput()->get('filter', [], 'array');

		foreach (array_keys($filters) as $name) {
			$value = $this->getState('filter.' . $name);

			if (!$this->isEmpty($value)) {
				$activeFilters[$name] = $value;
			}
		}

		return $activeFilters;
	}

	// =========================================================================
	// DEFAULTS
	// =========================================================================

	/**
	 * Get the filter form XML path
	 *
	 * @return string|null
	 */
	protected function getFilterFormPath(): ?string
	{
		if (empty($this->filterFormName)) {
			return null;
		}

		$componentPath = JPATH_SITE . '/components/' . $this->option;

		$formPaths = [
			$componentPath . '/forms/' . $this->filterFormName . '.xml',
//			$componentPath . '/models/forms/' . $this->filterFormName . '.xml', //not needed
//			$componentPath . '/model/form/' . $this->filterFormName . '.xml', // not needed
		];

		foreach ($formPaths as $path) {
			if (file_exists($path)) {
				return $path;
			}
		}

		return null;
	}

	/**
	 * Get defaults from XML form
	 *
	 * @return array
	 */
	public function getDefaults(): array
	{
		if ($this->defaults !== null) {
			return $this->defaults;
		}

		$this->defaults = $this->fallbackDefaults;

		$formPath = $this->getFilterFormPath();

		if ($formPath === null) {
			return $this->defaults;
		}

		$xml = simplexml_load_file($formPath);

		if (!$xml) {
			return $this->defaults;
		}

		foreach ($xml->fields as $fieldsGroup) {
			$groupName = (string) $fieldsGroup['name'];

			if (!isset($this->defaults[$groupName])) {
				$this->defaults[$groupName] = [];
			}

			foreach ($fieldsGroup->field as $field) {
				$name = (string) $field['name'];
				$default = (string) $field['default'];

				if ($default !== '') {
					$this->defaults[$groupName][$name] = $default;
				}
			}
		}

		return $this->defaults;
	}

	// =========================================================================
	// STATE MANAGEMENT
	// =========================================================================

	/**
	 * Populate state from URL
	 *
	 * @param string|null $ordering  Unused
	 * @param string|null $direction Unused
	 */
	protected function populateState($ordering = null, $direction = null): void
	{
		$defaults = $this->getDefaults();
		$input = $this->app->getInput();

		// Clear all session state - URL is the only source
		$this->app->setUserState($this->context . '.filter', null);
		$this->app->setUserState($this->context . '.list', null);
		$this->app->setUserState($this->context . '.limitstart', null);

		// List params from URL or defaults
		$list = $input->get('list', [], 'array');
		$this->populateListState($list, $defaults);

		// Pagination
		$limit = $this->state->get('list.limit');
		$limitstart = $input->getInt('limitstart', 0);
		$limitstart = $limit ? (int) (floor($limitstart / $limit) * $limit) : 0;
		$this->setState('list.start', $limitstart);

		// Filters from URL
		$filters = $input->get('filter', [], 'array');
		foreach ($filters as $name => $value) {
			$this->setState('filter.' . $name, $this->normalizeFilterValue($value));
		}

		// Layout
		$this->setState('layout', $input->getString('layout'));
	}

	/**
	 * Populate list state (ordering, limit)
	 *
	 * @param array $list     List input from URL
	 * @param array $defaults Defaults from XML
	 */
	protected function populateListState(array $list, array $defaults): void
	{
		$defaultLimit = (int) ($defaults['list']['limit'] ?? $this->fallbackDefaults['list']['limit']);
		$defaultFullordering = $defaults['list']['fullordering'] ?? $this->fallbackDefaults['list']['fullordering'];

		// Limit: URL or default
		$limit = isset($list['limit']) && $list['limit'] !== ''
			? (int) $list['limit']
			: $defaultLimit;

		$this->setState('list.limit', $limit);

		// Ordering: URL or default
		$fullordering = !empty($list['fullordering'])
			? $list['fullordering']
			: $defaultFullordering;

		[$orderCol, $orderDir] = $this->parseFullordering($fullordering);

		$this->setState('list.ordering', $orderCol);
		$this->setState('list.direction', $orderDir);
	}

	/**
	 * Parse fullordering string
	 *
	 * @param string $fullordering e.g. "a.name ASC"
	 * @return array [column, direction]
	 */
	protected function parseFullordering(string $fullordering): array
	{
		$parts = explode(' ', $fullordering);
		$fallbackParts = explode(' ', $this->fallbackDefaults['list']['fullordering']);

		$orderCol = $parts[0] ?? $fallbackParts[0];
		$orderDir = strtoupper($parts[1] ?? $fallbackParts[1] ?? 'DESC');

		if (!in_array($orderCol, $this->filter_fields)) {
			$orderCol = $fallbackParts[0];
			$orderDir = strtoupper($fallbackParts[1] ?? 'DESC');
		}

		if (!in_array($orderDir, ['ASC', 'DESC'])) {
			$orderDir = 'DESC';
		}

		return [$orderCol, $orderDir];
	}

	// =========================================================================
	// PAGINATION & URL HELPERS
	// =========================================================================

	/**
	 * Get pagination with clean URLs
	 *
	 * @return \Joomla\CMS\Pagination\Pagination
	 */
	public function getPagination()
	{
		$pagination = parent::getPagination();
		$input = $this->app->getInput();

		// Filters - only non-empty, non-default
		$filters = $input->get('filter', [], 'array');
		foreach ($filters as $name => $value) {
			$normalized = $this->normalizeFilterValue($value);

			if ($this->isEmpty($normalized) || $this->isDefault('filter', $name, $normalized)) {
				continue;
			}

			if (is_array($normalized)) {
				$pagination->setAdditionalUrlParam("filter[{$name}]", implode(',', $normalized));
			} else {
				$pagination->setAdditionalUrlParam("filter[{$name}]", $normalized);
			}
		}

		// List params - only if not default
		$currentFullordering = $this->state->get('list.ordering') . ' ' . $this->state->get('list.direction');
		if (!$this->isDefault('list', 'fullordering', $currentFullordering)) {
			$pagination->setAdditionalUrlParam('list[fullordering]', $currentFullordering);
		}

		$currentLimit = $this->state->get('list.limit');
		if (!$this->isDefault('list', 'limit', (string) $currentLimit)) {
			$pagination->setAdditionalUrlParam('list[limit]', $currentLimit);
		}

		return $pagination;
	}

	/**
	 * Get current non-default list params for URL building
	 *
	 * @return array
	 */
	public function getListUrlParams(): array
	{
		$params = [];

		$currentFullordering = $this->getState('list.ordering') . ' ' . $this->getState('list.direction');
		if (!$this->isDefault('list', 'fullordering', $currentFullordering)) {
			$params['list[fullordering]'] = $currentFullordering;
		}

		$currentLimit = $this->getState('list.limit');
		if (!$this->isDefault('list', 'limit', (string) $currentLimit)) {
			$params['list[limit]'] = $currentLimit;
		}

		return $params;
	}

	/**
	 * Build URL with current list params
	 *
	 * @param string $url Base URL
	 * @return string
	 */
	public function buildUrlWithListParams(string $url): string
	{
		$params = $this->getListUrlParams();

		if (empty($params)) {
			return $url;
		}

		$separator = strpos($url, '?') !== false ? '&' : '?';

		return $url . $separator . http_build_query($params);
	}

	// =========================================================================
	// FILTER HELPERS
	// =========================================================================

	/**
	 * Normalize filter value
	 *
	 * @param mixed $value Input value
	 * @return mixed Normalized value
	 */
	protected function normalizeFilterValue($value)
	{
		if ($value === null || $value === '' || $value === []) {
			return '';
		}

		if (is_array($value)) {
			return $this->normalizeArrayValue($value);
		}

		if (is_string($value) && strpos($value, ',') !== false) {
			return $this->normalizeArrayValue(explode(',', $value));
		}

		return is_string($value) ? trim($value) : $value;
	}

	/**
	 * Normalize array value
	 *
	 * @param array $value Input array
	 * @return array Normalized array
	 */
	protected function normalizeArrayValue(array $value): array
	{
		$value = array_map(fn($v) => is_string($v) ? trim($v) : $v, $value);
		$value = array_filter($value, fn($v) => $v !== '' && $v !== null);

		if (empty($value)) {
			return [];
		}

		$allNumeric = true;
		foreach ($value as $v) {
			if (!is_numeric($v)) {
				$allNumeric = false;
				break;
			}
		}

		if ($allNumeric) {
			return array_values(array_unique(array_filter(
				array_map('intval', $value),
				fn($v) => $v > 0
			)));
		}

		return array_values(array_unique($value));
	}

	/**
	 * Check if value is empty
	 *
	 * @param mixed $value
	 * @return bool
	 */
	protected function isEmpty($value): bool
	{
		if ($value === null || $value === '' || $value === []) {
			return true;
		}

		if (is_array($value)) {
			return empty(array_filter($value, fn($v) => $v !== '' && $v !== null));
		}

		return false;
	}

	/**
	 * Check if value equals default
	 *
	 * @param string $group Group name
	 * @param string $name  Field name
	 * @param mixed  $value Value to check
	 * @return bool
	 */
	protected function isDefault(string $group, string $name, $value): bool
	{
		$defaults = $this->getDefaults();
		$default = $defaults[$group][$name] ?? '';

		if (is_array($value)) {
			if (is_array($default)) {
				return empty(array_diff($value, $default)) && empty(array_diff($default, $value));
			}
			return empty($value) && ($default === '' || $default === []);
		}

		return (string) $value === (string) $default;
	}
}