<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/tests');

return (new PhpCsFixer\Config())
    ->setCacheFile('var/cache/php-cs-fixer')
    ->setFinder($finder)
    ->setRules(
        [
            '@Symfony' => true,
            'concat_space' => ['spacing' => 'one'],
            'class_definition' => ['space_before_parenthesis' => true],
            ['@PER-CS' => true]
        ]
    );
