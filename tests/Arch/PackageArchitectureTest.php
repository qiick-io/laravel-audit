<?php

declare(strict_types=1);

/**
 * Package-only architecture rules. Naming, suffixes, strict types and readonly
 * DTOs are the shared qiick rules in NamingArchitectureTest.php (configured in
 * naming-architecture.php).
 */
arch('the service provider keeps its ServiceProvider suffix')
    ->expect('Qiick\Audit\Laravel\AuditServiceProvider')
    ->toHaveSuffix('ServiceProvider');

arch('src never imports Illuminate Foundation types')
    ->expect('Qiick\Audit')
    ->not->toUse('Illuminate\Foundation');

arch('src never imports the product App namespace')
    ->expect('Qiick\Audit')
    ->not->toUse('App');
