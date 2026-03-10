<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP and Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/administrator/src',
        __DIR__ . '/site/src',
        __DIR__ . '/api/src',
        __DIR__ . '/plugins',
        __DIR__ . '/modules',
    ])
    ->name('*.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        '@PHP82Migration' => true,

        // Imports
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,
        'global_namespace_import' => [
            'import_classes' => true,
            'import_constants' => false,
            'import_functions' => false,
        ],

        // Spacing
        'array_indentation' => true,
        'binary_operator_spaces' => ['default' => 'single_space'],
        'concat_space' => ['spacing' => 'one'],
        'not_operator_with_successor_space' => false,
        'type_declaration_spaces' => true,
        'method_argument_space' => ['on_multiline' => 'ensure_fully_multiline'],

        // Clean code
        'no_extra_blank_lines' => ['tokens' => [
            'extra',
            'throw',
            'use',
            'curly_brace_block',
            'parenthesis_brace_block',
            'square_brace_block',
        ]],
        'no_trailing_comma_in_singleline' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
        'no_whitespace_before_comma_in_array' => true,
        'trim_array_spaces' => true,
        'single_quote' => true,
        'cast_spaces' => ['space' => 'single'],

        // Strict - disabled for Joomla compatibility
        'declare_strict_types' => false,
        'strict_comparison' => false,
        'strict_param' => false,

        // Arrays
        'array_syntax' => ['syntax' => 'short'],

        // Return types
        'return_type_declaration' => ['space_before' => 'none'],
        'nullable_type_declaration_for_default_null_value' => true,

        // PHPDoc
        'phpdoc_align' => ['align' => 'left'],
        'phpdoc_scalar' => true,
        'phpdoc_single_line_var_spacing' => true,
        'phpdoc_trim' => true,
        'phpdoc_types' => true,
        'no_empty_phpdoc' => true,
        'no_superfluous_phpdoc_tags' => ['allow_mixed' => true, 'remove_inheritdoc' => false],
    ])
    ->setFinder($finder);
