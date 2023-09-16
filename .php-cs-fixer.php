<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in(['src', 'tests'])
    ->exclude([
        'var',
        'vendor',
    ])
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@PER-CS1.0'                 => true,
        '@PER-CS1.0:risky'           => true,
        '@PHP80Migration:risky'      => true,
        '@PHP82Migration'            => true,
        'no_superfluous_phpdoc_tags' => true,
        'single_line_throw'          => false,
        'concat_space'               => ['spacing' => 'one'],
        'ordered_imports'            => true,
        'global_namespace_import'    => [
            'import_classes'   => true,
            'import_constants' => true,
            'import_functions' => true,
        ],
        'native_constant_invocation' => false,
        'native_function_invocation' => false,
        'modernize_types_casting'    => true,
        'is_null'                    => true,
        'array_syntax'               => [
            'syntax' => 'short',
        ],
        'final_public_method_for_abstract_class'           => true,
        'phpdoc_annotation_without_dot'                    => false,
        'phpdoc_summary'                                   => false,
        'logical_operators'                                => true,
        'class_definition'                                 => false,
        'binary_operator_spaces'                           => ['operators' => ['=>' => 'align_single_space_minimal', '=' => 'align_single_space_minimal']],
        'declare_strict_types'                             => true,
        'yoda_style'                                       => false,
        'no_unused_imports'                                => true,
        'use_arrow_functions'                              => false,
        'nullable_type_declaration_for_default_null_value' => true,
    ])
    ->setFinder($finder)
    ->setRiskyAllowed(true)
    ->setCacheFile(__DIR__ . '/var/.php_cs.cache')
;