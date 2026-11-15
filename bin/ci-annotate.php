<?php

declare(strict_types=1);

/*
 * Tells GitHub of the tests that failed, from PHPUnit's JUnit report: each becomes an annotation on the run, which
 * anyone can read on the run's page and through the API without logging in (the log itself needs a login).
 * Usage: php bin/ci-annotate.php [var/phpunit/junit.xml]
 */

$report = $argv[1] ?? \dirname(__DIR__) . '/var/phpunit/junit.xml';
$root = \dirname(__DIR__);
/** What GitHub reads as a message: one line, its line breaks and percent signs encoded. */
$encode = static fn(string $text): string => str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $text);

if (!is_file($report)) {
    echo '::error title=No test report::PHPUnit wrote no report at ' . $encode($report) . ": it stopped before its first test (the bootstrap, or the database could not be kept). See the step's log.\n";
    exit(0);
}
$xml = simplexml_load_file($report);
if ($xml === false) {
    echo "::error title=Unreadable test report::{$encode($report)} is not XML.\n";
    exit(0);
}
$failed = 0;
foreach ($xml->xpath('//testcase[failure or error]') ?: [] as $case) {
    foreach ([...$case->failure, ...$case->error] as $problem) {
        // Inside wp-env the plugin is at another path: from the folder that both have.
        $file = (string) $case['file'];
        foreach ([$root . '/', '/var/www/html/wp-content/plugins/orillacart/'] as $prefix) {
            if (str_starts_with($file, $prefix)) {
                $file = substr($file, \strlen($prefix));
            }
        }
        $title = (string) $case['class'] . '::' . (string) $case['name'];
        // The first 1500 characters: what failed and where; the rest is the trace.
        $message = mb_substr(trim((string) $problem), 0, 1500);
        printf("::error file=%s,line=%d,title=%s::%s\n", str_replace([',', ':'], ['%2C', '%3A'], $encode($file)), (int) $case['line'], str_replace([',', ':'], ['%2C', '%3A'], $encode($title)), $encode($message));
        ++$failed;
    }
}
echo $failed === 0 ? "No failed test in the report.\n" : "{$failed} failed.\n";
