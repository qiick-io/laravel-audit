<?php

declare(strict_types=1);

/**
 * This repo's settings for the shared `NamingArchitectureTest.php` (qiick standards).
 *
 * Written once by the sync, never overwritten or checked: it is this repo's own.
 * Package-only rules (Laravel namespace, no Foundation in src/, no App\) are in
 * `PackageArchitectureTest.php`.
 *
 * Public API names follow the binding design (§4), not the shared Contract/Enum
 * suffix table: design-mandated types are listed under ignoring with reasons as
 * they land.
 */
return [
    'namespace' => 'Qiick\Audit',

    'ignoring' => [
        'traits' => [
            // Design §4: the trait is Qiick\Audit\Concerns\LogsActivity, the
            // name of the Spatie trait it replaces in every product model.
            'Qiick\Audit\Concerns\LogsActivity',
        ],
    ],

    'suffixes' => [],

    'controllerPublicMethods' => [],
];
