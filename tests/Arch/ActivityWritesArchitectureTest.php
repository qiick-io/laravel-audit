<?php

declare(strict_types=1);

/*
 * Analysis AU-i: the model refuses to update or delete an audit row, but
 * the query builder is outside the model. Inside the package, only the
 * anonymiser and the retention purge write to activity_log that way; this
 * test fails when any other source file calls update() or delete() on a
 * query. (Products get the same rule from the README: the strong guarantee
 * is a database role without UPDATE / DELETE for the application.)
 */

it('writes to activity_log through the query builder only in the anonymiser and the purge', function (): void {
    $sourceRoot = dirname(__DIR__, 2).'/src';
    $writingFiles = [];

    $sourceFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS));

    foreach ($sourceFiles as $sourceFile) {
        if ($sourceFile->getExtension() !== 'php') {
            continue;
        }

        // Code only: docblocks may name the methods they warn about.
        $source = '';

        foreach (token_get_all((string) file_get_contents($sourceFile->getPathname())) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $source .= is_array($token) ? $token[1] : $token;
        }

        if (preg_match('/->\s*(update|delete|forceDelete|truncate|upsert|updateOrInsert)\s*\(/', $source) === 1) {
            $writingFiles[] = substr($sourceFile->getPathname(), strlen($sourceRoot) + 1);
        }
    }

    sort($writingFiles);

    expect($writingFiles)->toBe([
        'Anonymisation/ActivityAnonymiser.php',
        'Support/RetentionPurger.php',
    ]);
});
