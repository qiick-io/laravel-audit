<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Qiick\Audit\Contracts\AnonymisationReport;
use Qiick\Audit\Contracts\ErasureSubject;
use Qiick\Audit\Contracts\MorphRef;
use Qiick\Audit\Tests\Support\Models\AuthenticatableRecord;
use Qiick\Audit\Tests\Support\Models\IntegerKeyedRecord;

/*
 * The erasure contract's value objects (design §4, §5). MorphRef compares
 * through getMorphClass(), never the class name (A7).
 */

afterEach(function (): void {
    Relation::morphMap([], false);
    Relation::requireMorphMap(false);
});

it('builds a ref from a model with its morph class and string key', function (): void {
    $record = (new IntegerKeyedRecord)->forceFill(['id' => 42]);

    $ref = MorphRef::of($record);

    expect($ref->type)->toBe(IntegerKeyedRecord::class)
        ->and($ref->id)->toBe('42');
});

it('uses the morph map alias, not the class name (A7)', function (): void {
    Relation::morphMap(['user' => AuthenticatableRecord::class]);

    expect(MorphRef::of((new AuthenticatableRecord)->forceFill(['id' => 7]))->type)->toBe('user');
});

it('refuses a model without a key', function (): void {
    expect(fn () => MorphRef::of(new IntegerKeyedRecord))
        ->toThrow(InvalidArgumentException::class, 'A morph ref needs a model with an integer or string key.');
});

it('refuses an empty type or an id that does not fit the morph columns', function (string $type, string $id, string $message): void {
    expect(fn () => new MorphRef($type, $id))->toThrow(InvalidArgumentException::class, $message);
})->with([
    ['', '1', 'A morph ref needs a type.'],
    ['user', '', 'A morph ref id must have 1 to 64 characters.'],
    ['user', str_repeat('a', 65), 'A morph ref id must have 1 to 64 characters.'],
]);

it('accepts a 64-character id', function (): void {
    expect((new MorphRef('user', str_repeat('a', 64)))->id)->toHaveLength(64);
});

it('carries the refs, the tombstone and the identifiers', function (): void {
    $ref = new MorphRef('user', '1');
    $subject = new ErasureSubject([$ref], 'erased-user-0193a3b0', ['ada@example.com', 'Ada Lovelace']);

    expect($subject->refs)->toBe([$ref])
        ->and($subject->tombstone)->toBe('erased-user-0193a3b0')
        ->and($subject->identifiers)->toBe(['ada@example.com', 'Ada Lovelace']);
});

it('refuses an erasure subject that could not be erased cleanly', function (array $refs, string $tombstone, array $identifiers, string $message): void {
    expect(fn () => new ErasureSubject($refs, $tombstone, $identifiers))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'no refs' => [[], 'erased-1', [], 'An erasure subject needs at least one morph ref.'],
    'not a ref' => [['user:1'], 'erased-1', [], 'An erasure subject needs at least one morph ref.'],
    'empty tombstone' => [[new MorphRef('user', '1')], ' ', [], 'The tombstone must not be empty.'],
    'empty identifier' => [[new MorphRef('user', '1')], 'erased-1', [''], 'An identifier must be a non-empty string.'],
    'blank identifier' => [[new MorphRef('user', '1')], 'erased-1', ['  '], 'An identifier must be a non-empty string.'],
    'non-string identifier' => [[new MorphRef('user', '1')], 'erased-1', [42], 'An identifier must be a non-empty string.'],
    'tombstone contains an identifier' => [[new MorphRef('user', '1')], 'erased-ada', ['ADA'], 'The tombstone must not contain an identifier, or erasure would never settle.'],
]);

it('reports the anonymised row count', function (): void {
    expect((new AnonymisationReport(3))->rows)->toBe(3);
});
