<?php

declare(strict_types=1);

/*
 * Which files of the bundled libraries the release ships, by allowlist: their PHP, outside the folders that hold
 * only their tests and test helpers, and the few data files they read when they run (Dompdf's fonts, style sheet
 * and colour profile, php-font-lib's character maps), with their licences. Anything else (schemas, translation
 * catalogues, shell completions, change logs) stays out. PHP-Scoper selects the files with it (scoper.inc.php)
 * and bin/check-build.php fails a build that has any other. Paths are relative to vendor/.
 */

return [
    // PHP files in these folders are left out.
    'php_excluded' => [
        '#(^|/)(test|tests|Tests|bin|doc|docs)/#',
        '#^symfony/[^/]+/(Test|Tester)/#',
        '#^twig/twig/src/Test/#',
    ],
    // Every other file the release keeps.
    'data' => [
        '#^dompdf/dompdf/VERSION$#',
        '#^dompdf/dompdf/lib/res/(html\.css|broken_image\.(png|svg)|sRGB2014\.icc)$#',
        '#^dompdf/dompdf/lib/fonts/[^/]+\.(afm|ufm|ttf|json)$#',
        '#^dompdf/php-font-lib/maps/[^/]+\.map$#',
        '#^composer/installed\.json$#',
        // Licences, which go with the code they cover.
        '#^([^/]+/[^/]+/|composer/)(LICEN[CS]E|COPYING|NOTICE|CREDITS)(\.[A-Za-z]+)?$#',
        '#^dompdf/dompdf/lib/res/sRGB2014\.icc\.LICENSE$#',
    ],
];
