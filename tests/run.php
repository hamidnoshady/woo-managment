<?php

/**
 * Test entry point. Run with:  php tests/run.php
 *
 * Discovers every tests/*Test.php file, runs the registered tests, and exits
 * non-zero if any assertion failed (so CI turns red on failure).
 */

require __DIR__ . '/lib.php';

foreach (glob(__DIR__ . '/*Test.php') as $file) {
    require $file;
}

fwrite(STDOUT, "Running " . count($GLOBALS['__tests']) . " tests\n\n");

exit(run_tests());
