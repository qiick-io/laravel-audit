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
        'Contracts' => [
            // Design §4 names the public API without the Contract suffix, and
            // AuditActor is a readonly value object, not an interface.
            'Qiick\Audit\Contracts\AuditActor',
            'Qiick\Audit\Contracts\AuditActorResolver',
            'Qiick\Audit\Contracts\OrganisationResolver',
            'Qiick\Audit\Contracts\AuditAnonymiser',
            'Qiick\Audit\Contracts\ErasureSubject',
            'Qiick\Audit\Contracts\MorphRef',
            'Qiick\Audit\Contracts\AnonymisationReport',
        ],
        'Events' => [
            // Design §4 names the event ActivityAnonymised.
            'Qiick\Audit\Events\ActivityAnonymised',
        ],
        'traits' => [
            // Design §4: the trait is Qiick\Audit\Concerns\LogsActivity, the
            // name of the Spatie trait it replaces in every product model.
            'Qiick\Audit\Concerns\LogsActivity',
            // The names of the Spatie traits they replace in product models.
            'Qiick\Audit\Concerns\CausesActivity',
            'Qiick\Audit\Concerns\HasActivity',
        ],
    ],

    'suffixes' => [],

    'controllerPublicMethods' => [],
];
