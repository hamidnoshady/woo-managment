<?php

/**
 * Code-style rules for CI (.github/workflows/ci.yml).
 *
 * Based on PSR-12, which matches the existing codebase (4-space indent,
 * short array syntax, one class per file). The CI job runs this in
 * --dry-run mode against only the PHP files a pull request changes, so
 * legacy files are never flagged - only new or modified code is enforced.
 *
 * Run locally to auto-fix before pushing:
 *   php-cs-fixer fix
 */

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/public', __DIR__ . '/includes', __DIR__ . '/wp-plugin'])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays']],
        'no_trailing_whitespace' => true,
        'no_whitespace_in_blank_line' => true,
        // View files legitimately mix PHP with trailing HTML, so leave
        // closing-tag handling to the developer rather than forcing removal.
        'no_closing_tag' => false,
    ])
    ->setFinder($finder);
