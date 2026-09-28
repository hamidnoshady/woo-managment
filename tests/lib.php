<?php

/**
 * Minimal, dependency-free test harness.
 *
 * This repo deliberately has no Composer/build step (see CLAUDE.md), so we
 * ship a tiny assertion runner instead of pulling in PHPUnit. Each test file
 * calls test('name', fn) and the assert_* helpers below; run.php includes the
 * files and reports pass/fail, exiting non-zero if anything failed.
 */

$GLOBALS['__tests'] = [];
$GLOBALS['__test_stats'] = ['passed' => 0, 'failed' => 0];

function test(string $name, callable $fn): void
{
    $GLOBALS['__tests'][] = ['name' => $name, 'fn' => $fn];
}

class AssertionFailed extends RuntimeException
{
}

function assert_true($value, string $message = ''): void
{
    if ($value !== true) {
        throw new AssertionFailed($message !== '' ? $message : 'Expected true, got ' . var_export($value, true));
    }
}

function assert_same($expected, $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new AssertionFailed(
            ($message !== '' ? $message . ' — ' : '') .
            'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
}

function assert_equals($expected, $actual, string $message = ''): void
{
    if ($expected != $actual) {
        throw new AssertionFailed(
            ($message !== '' ? $message . ' — ' : '') .
            'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
}

function run_tests(): int
{
    $stats = &$GLOBALS['__test_stats'];
    foreach ($GLOBALS['__tests'] as $t) {
        try {
            ($t['fn'])();
            $stats['passed']++;
            fwrite(STDOUT, "  \033[32mPASS\033[0m {$t['name']}\n");
        } catch (Throwable $e) {
            $stats['failed']++;
            fwrite(STDOUT, "  \033[31mFAIL\033[0m {$t['name']}\n");
            fwrite(STDOUT, "       " . $e->getMessage() . "\n");
        }
    }

    fwrite(STDOUT, "\n{$stats['passed']} passed, {$stats['failed']} failed\n");
    return $stats['failed'] === 0 ? 0 : 1;
}
