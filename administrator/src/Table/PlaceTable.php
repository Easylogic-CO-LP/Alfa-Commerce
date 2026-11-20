<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Table;

// No direct access
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Service\Place\PlaceTree;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseDriver;

/**
 * Place table
 *
 * @since  1.0.0
 */
class PlaceTable extends Table
{
    /**
     * Indicates that columns fully support the NULL value in the database
     *
     * @var bool
     * @since  1.0.0
     */
    protected $_supportNullValue = true;

    /**
     * Constructor
     *
     * @param JDatabase &$db A database connector object
     * @since  1.0.0
     */
    public function __construct(DatabaseDriver $db)
    {
        $this->typeAlias = 'com_alfa.place';
        parent::__construct('#__alfa_places', 'id', $db);
        $this->setColumnAlias('published', 'state');
    }

    /**
     * The codes of a place, as its level allows.
     *
     * A COUNTRY, a place with no parent, carries its ISO 3166-1 codes: two
     * letters in code2 and three in code3, stored in capitals, and no ISO
     * 3166-2 code. A PLACE BELOW A COUNTRY carries no country codes: its
     * country's are read through the places above it (PlaceTree::countryOf()),
     * so code2 and code3 are stored empty, and a code sent for it is dropped.
     * It may carry its ISO 3166-2 code, which starts with its country's two
     * letters (GR-I under Greece).
     *
     * @return bool True when the place may be stored.
     *
     * @since  2.2.1
     */
    public function check()
    {
        if (!parent::check()) {
            return false;
        }

        $parent = (int) ($this->parent_id ?? 0);
        $subdivision = strtoupper(trim((string) ($this->subdivision_code ?? '')));

        if ($parent <= 0) {
            $this->code2 = strtoupper(trim((string) ($this->code2 ?? '')));
            $this->code3 = strtoupper(trim((string) ($this->code3 ?? '')));

            if (preg_match('/^[A-Z]{2}$/', $this->code2) !== 1 || preg_match('/^[A-Z]{3}$/', $this->code3) !== 1) {
                $this->setError(Text::_('COM_ALFA_PLACE_COUNTRY_CODES_REQUIRED'));

                return false;
            }

            if ($subdivision !== '') {
                $this->setError(Text::_('COM_ALFA_PLACE_SUBDIVISION_CODE_COUNTRY'));

                return false;
            }

            $this->subdivision_code = null;

            return true;
        }

        $this->code2 = '';
        $this->code3 = '';
        $this->subdivision_code = $subdivision === '' ? null : $subdivision;

        if ($subdivision === '') {
            return true;
        }

        if (preg_match('/^[A-Z]{2}-[A-Z0-9]{1,3}$/', $subdivision) !== 1) {
            $this->setError(Text::sprintf('COM_ALFA_PLACE_SUBDIVISION_CODE_INVALID', $subdivision));

            return false;
        }

        // The tree as stored now: a country made earlier in this request is in it.
        PlaceTree::forget();
        $country = PlaceTree::countryOf(placeId: $parent)['code2'] ?? '';

        if ($country !== '' && !str_starts_with($subdivision, $country . '-')) {
            $this->setError(Text::sprintf('COM_ALFA_PLACE_SUBDIVISION_CODE_OTHER_COUNTRY', $subdivision, $country));

            return false;
        }

        return true;
    }

    /**
     * Method to store a row in the database from the Table instance properties.
     *
     * If a primary key value is set the row with that primary key value will be updated with the instance property values.
     * If no primary key value is set a new row will be inserted into the database with the properties from the Table instance.
     *
     * @param bool $updateNulls True to update fields even if they are null.
     *
     * @return bool True on success.
     *
     * @since  1.0.0
     */
    public function store($updateNulls = true)
    {
        return parent::store($updateNulls);
    }
}
