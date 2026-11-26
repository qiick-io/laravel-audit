<?php

declare(strict_types=1);

namespace Qiick\Audit\Contracts;

use InvalidArgumentException;

/**
 * The data subject whose audit data an erasure anonymises (design §4, §5).
 *
 * The tombstone replaces each personal value. Replacing a whole word inside
 * the tombstone would expand it on every run. Replacing the entire tombstone
 * with itself is safe.
 *
 * @api
 */
// @pest-arch-ignore-next-line — design §4: readonly value object under Contracts/, not an interface
final readonly class ErasureSubject
{
    /**
     * @param  list<MorphRef>  $refs  every model that identifies the person: user, principals, keys
     * @param  list<string>  $identifiers  emails, usernames and names to scrub from free text
     */
    public function __construct(public array $refs, public string $tombstone, public array $identifiers = [])
    {
        if ($refs === [] || array_filter($refs, fn (mixed $ref): bool => ! $ref instanceof MorphRef) !== []) {
            throw new InvalidArgumentException('An erasure subject needs at least one morph ref.');
        }

        if (trim($tombstone) === '') {
            throw new InvalidArgumentException('The tombstone must not be empty.');
        }

        foreach ($identifiers as $identifier) {
            if (! is_string($identifier) || trim($identifier) === '') {
                throw new InvalidArgumentException('An identifier must be a non-empty string.');
            }

            if (preg_match(self::identifierPattern($identifier), $tombstone, $matches) === 1 && $matches[0] !== $tombstone) {
                throw new InvalidArgumentException('The tombstone must not contain an identifier, or erasure would never settle.');
            }
        }
    }

    /** @internal Match identifiers at the same boundaries in validation and scrubbing. */
    public static function identifierPattern(string $identifier): string
    {
        return '/(?<![\p{L}\p{N}])'.preg_quote($identifier, '/').'(?![\p{L}\p{N}])/iu';
    }
}
