<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Helper\RequestScalar;
use Alfa\Component\Alfa\Administrator\Service\Catalog\ItemFieldValueService;
use Alfa\Component\Alfa\Administrator\Service\Refusal;
use Alfa\Component\Alfa\Api\Helper\ApiInclude;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\ApiController;
use Joomla\CMS\MVC\Controller\Exception\ResourceNotFound;
use Joomla\CMS\MVC\Controller\Exception\Save;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;

/**
 * An item field's values over the REST API, through the one way values are written ({@see ItemFieldValueService}):
 *
 * - GET v1/alfa/itemfields/:id/values: the field's values in their order, filter[code] (exact);
 * - GET v1/alfa/itemfields/:id/values/:valueId: one value;
 * - POST v1/alfa/itemfields/:id/values: a new value, last in the list unless an order is sent;
 * - PATCH v1/alfa/itemfields/:id/values/:valueId: a change of the fields sent;
 * - DELETE v1/alfa/itemfields/:id/values/:valueId: refused 409 while a product uses the value.
 *
 * A value reads its name in the request's language (lang, else the shop's default content language) and, with
 * include=translations, every language's name and alias; a write takes the same translations object (the alias is made
 * from the name). Writes ask alfa.itemfields.edit (the task is edit for the three), reads alfa.itemfields.view.
 *
 * @since  2.3.0
 */
class ItemfieldvaluesController extends ApiController
{
    use HasApiGuards;
    use AnswersFromServices;
    use ReadsCatalogBodies;

    /**
     * @var string
     * @since  2.3.0
     */
    protected $contentType = 'itemfieldvalues';

    /**
     * @var string
     * @since  2.3.0
     */
    protected $default_view = 'itemfieldvalues';

    /**
     * A value is its field's: the item fields' permissions.
     *
     * @since  2.3.0
     */
    protected function aclSection(): string
    {
        return 'itemfields';
    }

    /**
     * The field's values in their order; filter[code] finds one by its code.
     *
     * @return static
     *
     * @since  2.3.0
     */
    public function displayList()
    {
        $fieldId = $this->requireField();
        $filter = $this->input->get('filter', [], 'array');
        $rows = ItemFieldValueService::read(fieldId: $fieldId);

        if (\array_key_exists('code', $filter)) {
            $code = trim((string) RequestScalar::member($filter, 'code', 'STRING', 'filter'));

            if (preg_match(ItemFieldValueService::CODE_PATTERN, $code) !== 1) {
                throw new Save('filter[code] must be a value code: 1 to 64 characters, a to z, 0 to 9, - and _.', 400);
            }

            $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['code'] === $code));
        }

        return $this->answer(data: array_map(static fn (array $row): array => self::shown(row: $row), $rows));
    }

    /**
     * One value of the field.
     *
     * @param int|null $id Unused: the route names the field and the value.
     *
     * @return static
     *
     * @since  2.3.0
     */
    public function displayItem($id = null)
    {
        return $this->answer(data: self::shown(row: $this->requireValue(fieldId: $this->requireField(), valueId: $this->routeId(name: 'valueId'))));
    }

    /**
     * POST creates a value; PATCH changes one; DELETE removes one. The task is edit for the three, so each asks the
     * item fields' edit permission.
     *
     * @return static
     *
     * @since  2.3.0
     */
    public function edit()
    {
        $fieldId = $this->requireField();
        $valueId = $this->routeId(name: 'valueId');
        $method = strtoupper($this->input->getMethod());

        if ($method === 'DELETE') {
            $result = ItemFieldValueService::of(fieldId: $fieldId)->remove(valueIds: [$valueId])->save();

            if (!$result->saved) {
                return $this->refuse(refusals: $result->refusals);
            }

            $this->app->setHeader('status', '204', true);

            return $this;
        }

        $creating = $method === 'POST' && $valueId === 0;
        $body = $this->bodyObject(example: '{"code": "silver", "translations": {"en_gb": {"name": "Silver"}}}');
        [$fields, $refusals, $warnings] = $this->valueFields(body: $body);

        if ($refusals !== []) {
            return $this->refuse(refusals: $refusals);
        }

        $write = ItemFieldValueService::of(fieldId: $fieldId);
        $result = ($creating ? $write->create(fields: $fields) : $write->change(valueId: $valueId, fields: $fields))->save();

        if (!$result->saved) {
            return $this->refuse(refusals: $result->refusals);
        }

        $id = $creating ? (int) ($result->created[0] ?? 0) : $valueId;
        $this->answer(data: self::shown(row: $this->requireValue(fieldId: $fieldId, valueId: $id)), status: $creating ? 201 : 200, warnings: $warnings);

        if ($creating) {
            $this->app->setHeader('Location', Uri::root() . 'api/index.php/v1/alfa/itemfields/' . $fieldId . '/values/' . $id, true);
        }

        return $this;
    }

    /**
     * A value as a read shows it: its name in the request's language, its translations when asked.
     *
     * @param array $row A row of ItemFieldValueService::read().
     *
     * @since  2.3.0
     */
    private static function shown(array $row): array
    {
        $language = MultilingualHelper::normaliseTag(tag: MultilingualHelper::getCurrentLanguageTag());
        $shown = ['name' => (string) ($row['translations'][$language]['name'] ?? '')] + $row;

        if (!ApiInclude::asks(ApiInclude::TRANSLATIONS)) {
            unset($shown['translations']);
        }

        return $shown;
    }

    /**
     * The route's field; 404 for one that does not exist.
     *
     * @throws ResourceNotFound When it does not (404).
     *
     * @since  2.3.0
     */
    private function requireField(): int
    {
        $fieldId = $this->routeId(name: 'id');
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        if ($fieldId <= 0 || (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__alfa_item_fields') . ' WHERE id = ' . $fieldId)->loadResult() === 0) {
            throw new ResourceNotFound(Text::_('COM_ALFA_ITEMFIELD_NOT_FOUND'), 404);
        }

        return $fieldId;
    }

    /**
     * A value of the field as read; 404 for a value of another field or none.
     *
     * @throws ResourceNotFound When the field has no such value (404).
     *
     * @since  2.3.0
     */
    private function requireValue(int $fieldId, int $valueId): array
    {
        $rows = ItemFieldValueService::read(fieldId: $fieldId, valueIds: [$valueId]);

        if ($rows === []) {
            throw new ResourceNotFound(Text::sprintf('COM_ALFA_ITEMFIELD_VALUE_NOT_FOUND', (string) $valueId), 404);
        }

        return $rows[0];
    }
}
