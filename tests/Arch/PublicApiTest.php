<?php

declare(strict_types=1);
use Illuminate\Support\Arr;

/*
 * docs/public-api.md is the public surface (checklist item 14): every class,
 * interface, enum and trait in src/ says whether it belongs to it (@api) or
 * not (@internal), and the page lists every @api one, and nothing else.
 */

/**
 * @return array<string, string> fully qualified name => the docblock right above the declaration
 */
function sourceDeclarationDocblocks(): array
{
    $docblocks = [];
    $sourceRoot = dirname(__DIR__, 2).'/src';
    $sourceFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS));

    foreach ($sourceFiles as $sourceFile) {
        $source = (string) file_get_contents((string) $sourceFile);

        // A `//` line between the docblock and the declaration (the arch-ignore anchors) is allowed.
        $isDeclared = preg_match('/(\/\*\*(?:(?!\*\/).)*\*\/)\s*\n(?:\/\/[^\n]*\n)*(?:final |abstract )?(?:readonly )?(?:class|interface|enum|trait) (\w+)/s', $source, $match) === 1;
        preg_match('/^namespace ([^;]+);/m', $source, $namespace);

        expect($isDeclared)->toBeTrue("{$sourceFile} must document its declaration.");

        $docblocks[$namespace[1].'\\'.$match[2]] = $match[1];
    }

    ksort($docblocks);

    return $docblocks;
}

it('marks every declaration in src as @api or @internal, never both', function (): void {
    foreach (sourceDeclarationDocblocks() as $declaration => $docblock) {
        expect(str_contains($docblock, '@api') xor str_contains($docblock, '@internal'))
            ->toBeTrue("{$declaration} must be marked either @api or @internal.");
    }
});

it('lists every @api declaration in docs/public-api.md, and nothing else', function (): void {
    $page = (string) file_get_contents(dirname(__DIR__, 2).'/docs/public-api.md');
    preg_match_all('/^- `(Qiick\\\\Audit\\\\[\w\\\\]+)` \((?:class|interface|enum|trait)\)$/m', $page, $listed);

    $apiDeclarations = array_keys(array_filter(
        sourceDeclarationDocblocks(),
        static fn (string $docblock): bool => str_contains($docblock, '@api'),
    ));
    $listedDeclarations = $listed[1];
    sort($listedDeclarations);

    expect($listedDeclarations)->toBe($apiDeclarations);
});

it('lists every config key of config/audit.php in docs/public-api.md', function (): void {
    $page = (string) file_get_contents(dirname(__DIR__, 2).'/docs/public-api.md');
    $configKeys = array_keys(Arr::dot(require dirname(__DIR__, 2).'/config/audit.php'));
    $configKeys = array_values(array_unique(array_map(
        static fn (string $key): string => preg_replace('/\.\d+$/', '', preg_replace('/^(columns|sensitive_attributes)\..*/', '$1', $key)),
        $configKeys,
    )));

    foreach ($configKeys as $configKey) {
        expect($page)->toContain("- `audit.{$configKey}`");
    }
});
