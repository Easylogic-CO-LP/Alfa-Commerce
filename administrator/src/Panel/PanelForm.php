<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Panel;

\defined('_JEXEC') or die;

use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormHelper;
use Throwable;

/**
 * Loads the Joomla form a panel renders: a plugin names it ({@see PanelViewEvent::useForm()}),
 * this builds it the native way and hands it back bound to the record.
 *
 * The one thing the framework adds over a bare Form::getInstance is REACH: com_alfa's own
 * field-type namespace is registered first, so a plugin's form XML may use every Alfa field
 * type (the multilingual ones included) exactly as the core Alfa edit screens do, without
 * the plugin knowing the namespace. The form is built with no control prefix, so its fields
 * post under their plain names (title, terms, ...), the same names the plugin already reads
 * in onAlfaPanelSave. Rendering is the framework's job; validating and persisting stay the
 * plugin's, through its own service.
 *
 * @since  1.7.0
 */
final class PanelForm
{
    /**
     * The Alfa field-type namespace: registered so plugin forms resolve `type="MultilingualText"`
     * and every other Alfa field, the same prefix com_alfa's own config/forms use.
     */
    private const FIELD_PREFIX = 'Alfa\\Component\\Alfa\\Administrator\\Field';

    /**
     * Load and bind a panel's form, or null when the plugin ships no such form file.
     *
     * @param PanelIdentity $identity The owning panel.
     * @param string $name Form file basename (in the plugin's forms/ dir).
     * @param array<string, mixed> $data Record data to bind (plain field-name keys).
     *
     *
     * @since  1.7.0
     */
    public static function load(PanelIdentity $identity, string $name, array $data = []): ?Form
    {
        $file = $identity->pluginPath() . '/forms/' . $name . '.xml';

        if (!is_file($file)) {
            return null;
        }

        // Alfa's field types (incl. multilingual) become available to the plugin's form.
        FormHelper::addFieldPrefix(self::FIELD_PREFIX);

        try {
            $form = Form::getInstance(
                'com_alfa.panel.' . preg_replace('/[^a-z0-9]+/i', '.', $identity->key()) . '.' . $name,
                file_get_contents($file),
                ['control' => ''],
            );
        } catch (Throwable $e) {
            return null;
        }

        if ($data !== []) {
            $form->bind($data);
        }

        return $form;
    }
}
