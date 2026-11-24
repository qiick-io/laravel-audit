<?php

declare(strict_types=1);

it('loads the package migration by default', function (): void {
    $loadedPaths = collect(app('migrator')->paths())->map(fn (string $path): string|false => realpath($path))->all();

    expect($loadedPaths)->toContain(realpath(dirname(__DIR__, 2).'/database/migrations'));
});
