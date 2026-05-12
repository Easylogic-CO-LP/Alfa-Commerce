<?php

defined('_JEXEC') or die;

use Joomla\Registry\Registry;

$value = $field->value ?? '';
if ($value === '' || $value === null || $value === '[]') {
    return;
}

$decoded = is_string($value) ? json_decode($value, true) : (array) $value;
if (!is_array($decoded) || empty($decoded)) {
    $decoded = [(string) $value];
}

$params = is_string($field->fieldparams)
    ? new Registry($field->fieldparams)
    : ($field->fieldparams ?? new Registry());

$options = (array) $params->get('options', []);
$lookup = [];
foreach ($options as $opt) {
    $v = is_object($opt) ? ($opt->value ?? '') : ($opt['value'] ?? '');
    $t = is_object($opt) ? ($opt->text ?? '') : ($opt['text'] ?? '');
    $lookup[(string) $v] = (string) $t;
}

$labels = [];
foreach ($decoded as $v) {
    $labels[] = $lookup[(string) $v] ?? (string) $v;
}

echo '<span class="alfa-choice__display">'
    . htmlspecialchars(implode(', ', $labels), ENT_QUOTES, 'UTF-8')
    . '</span>';
