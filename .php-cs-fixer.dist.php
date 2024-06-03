<?php

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__])
    ->exclude('vendor')
;

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony'                              => true,
        '@Symfony:risky'                        => true,
        'linebreak_after_opening_tag'           => true,
        'mb_str_functions'                      => true,
        'no_php4_constructor'                   => true,
        'no_unreachable_default_argument_value' => true,
        'no_useless_else'                       => true,
        'no_useless_return'                     => true,
        'php_unit_strict'                       => true,
        'phpdoc_order'                          => true,
        'strict_comparison'                     => true,
        'strict_param'                          => true,
        'array_syntax'                          => ['syntax' => 'short'],
        'binary_operator_spaces'                => [
            'operators' => [
                '='  => 'align',
                '=>' => 'align',
            ],
        ],
        'concat_space'               => ['spacing' => 'one'],
        'native_function_invocation' => [
            'include' => ['@internal'],
        ],
        'ordered_imports'                  => true,
        'random_api_migration'             => true,
        'phpdoc_summary'                   => false,
        'blank_line_between_import_groups' => false,
    ])
    ->setFinder($finder);
