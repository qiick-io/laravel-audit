<?php

declare(strict_types=1);

namespace Qiick\Audit\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ReflectionMethod;
use ReflectionNamedType;
use Spatie\Activitylog\Support\ChangeDetector;

/**
 * The secret floor of the audit log (design §4 "trait secret floor", A1).
 *
 * A secret value never reaches the log, but the fact that it changed does:
 * its value becomes `[redacted]` and the key stays, so "the password changed"
 * or "two-factor was confirmed" is still recorded (OWASP Logging; review B5).
 *
 * A value is secret when:
 * - the model that owns the attribute casts it as encrypted* or hashed;
 * - its name, normalised (lower case, no `_ - .` or whitespace), equals a
 *   floor name: the built-in list, `audit.sensitive_attributes`;
 * - its name contains a secret part (`password`, `token`, …) or a part from
 *   `audit.sensitive_parts`, unless the name or cast says the value is a
 *   moment, a flag or a count (`two_factor_confirmed_at`, a `datetime`,
 *   `boolean` or `integer` cast), which is not a secret itself.
 *
 * It covers every path Spatie can log: JSON paths (`settings->secret`),
 * relation paths (`owner.password`, checked on the related model through
 * relation methods typed as a Relation, never by calling another method),
 * and keys nested at any depth in a logged JSON value. A path that names a
 * relation, or goes through a segment that is not one, is not logged at all,
 * nor is any value that holds a model (review B1).
 *
 * @internal
 */
final class SecretAttributeFloor
{
    /** What a secret value is replaced with. */
    public const string REDACTED = '[redacted]';

    /** @var list<string> normalised exact floor names */
    private readonly array $floorNames;

    /** @var list<string> normalised secret parts */
    private readonly array $secretParts;

    /**
     * @param  list<string>  $configuredAttributes  audit.sensitive_attributes
     * @param  list<string>  $configuredParts  audit.sensitive_parts
     */
    public function __construct(array $configuredAttributes, array $configuredParts = [])
    {
        $this->floorNames = array_map(self::normalise(...), [...self::builtInAttributes(), ...$configuredAttributes]);
        $this->secretParts = array_map(self::normalise(...), [...self::builtInSecretParts(), ...$configuredParts]);
    }

    /**
     * Parts of a name that make it secret wherever they appear (review B4).
     * Short abbreviations (`pass`, `pin`) are exact names instead, or they
     * would match `passport` or `shipping`.
     *
     * @return list<string>
     */
    public static function builtInSecretParts(): array
    {
        return [
            'password', 'secret', 'token', 'privatekey', 'apikey', 'twofactor', 'recoverycode', 'remember',
            'passphrase', 'passwd', 'credential', 'authorization', 'cookie', 'session', 'segreto',
        ];
    }

    /**
     * The exact names a product can add to but never remove: the config list
     * is merged with it, so an emptied config keeps these.
     *
     * @return list<string>
     */
    public static function builtInAttributes(): array
    {
        return [
            'password',
            'remember_token',
            'two_factor_secret',
            'two_factor_recovery_codes',
            'key_hash',
            'previous_key_hash',
            'secret',
            'token_hash',
            'private_key',
            'pwd',
            'pass',
            'otp',
            'pin',
            'cvv',
            'iban',
            'signing_key',
            'dsn',
        ];
    }

    /**
     * Builds the floor from `audit.sensitive_attributes` and
     * `audit.sensitive_parts`. A value that is not a list of strings is a
     * misconfiguration of a security control, so it fails loudly instead of
     * logging with a partial floor.
     */
    public static function fromConfig(mixed $configuredAttributes, mixed $configuredParts = []): self
    {
        return new self(
            self::listOfNames($configuredAttributes, 'audit.sensitive_attributes must be a list of attribute names.'),
            self::listOfNames($configuredParts, 'audit.sensitive_parts must be a list of name parts.'),
        );
    }

    /** The floor of this application: `audit.sensitive_attributes` and `audit.sensitive_parts`. */
    public static function configured(): self
    {
        return self::fromConfig(config('audit.sensitive_attributes'), config('audit.sensitive_parts'));
    }

    /**
     * The paths Spatie may resolve at all: a path that names a relation, or
     * goes through a segment that is not a typed relation, would log a model
     * or call an unknown method, so it is dropped (fail closed).
     *
     * @param  array<mixed>  $attributePaths  what Spatie would log
     * @return list<string> the loggable paths, in their order
     */
    public function loggablePaths(Model $model, array $attributePaths): array
    {
        $loggablePaths = [];

        foreach ($attributePaths as $attributePath) {
            if (! is_string($attributePath)) {
                throw new InvalidArgumentException('An attribute to log must be named by a string.');
            }

            $owningModel = $this->owningModel($model, $attributePath);
            $column = explode('->', self::lastSegment($attributePath))[0];

            if ($owningModel !== null && ! $this->namesARelation($owningModel, $column)) {
                $loggablePaths[] = $attributePath;
            }
        }

        return $loggablePaths;
    }

    /**
     * Whether the value at this path must not appear in the log or in a
     * description.
     */
    public function isSecret(Model $model, string $attributePath): bool
    {
        $owningModel = $this->owningModel($model, $attributePath);

        if ($owningModel === null) {
            return true;
        }

        $jsonKeys = explode('->', self::lastSegment($attributePath));
        $column = array_shift($jsonKeys);
        $castType = self::castTypeOf($owningModel, $column);

        if (self::isSecretCast($castType) || $this->namesARelation($owningModel, $column)) {
            return true;
        }

        return $this->isFloorName($column, $castType) || $this->namesAFloorKey($jsonKeys);
    }

    /**
     * @param  list<string>  $keys
     */
    public function namesAFloorKey(array $keys): bool
    {
        foreach ($keys as $key) {
            if ($this->isFloorName($key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Spatie's resolved values without the values that hold a model (a
     * relation logged by name, `owner.owner`): their toArray() would carry
     * the related model's secrets, decrypted (review B1).
     *
     * @param  array<array-key, mixed>  $changes  attribute path => value
     * @return array<array-key, mixed>
     */
    public function withoutModels(array $changes): array
    {
        return array_filter($changes, fn (mixed $value): bool => ! self::holdsModels($value));
    }

    /**
     * The attribute_changes of a row, ready to store: every secret value, at
     * a logged path or at any depth inside a logged value, is `[redacted]`.
     *
     * @param  array<array-key, mixed>  $attributeChanges  `attributes` / `old` => path => value
     * @param  mixed  $subject  the model the paths belong to, when there is one
     * @return array<array-key, mixed>
     */
    public function redactChanges(array $attributeChanges, mixed $subject): array
    {
        $redactedChanges = [];

        foreach ($attributeChanges as $section => $valuesByPath) {
            if (! is_array($valuesByPath)) {
                $redactedChanges[$section] = $this->redactNestedKeys($valuesByPath);

                continue;
            }

            $redactedSection = [];

            foreach ($valuesByPath as $attributePath => $value) {
                $isSecret = is_string($attributePath) && ($subject instanceof Model
                    ? $this->isSecret($subject, $attributePath)
                    : $this->namesAFloorKey(explode('.', str_replace('->', '.', $attributePath))));

                $redactedSection[$attributePath] = $isSecret ? self::REDACTED : $this->redactNestedKeys($value);
            }

            $redactedChanges[$section] = $redactedSection;
        }

        return $redactedChanges;
    }

    private function redactNestedKeys(mixed $value): mixed
    {
        // Collections, array objects and value objects reach the log as
        // their JSON form, so they are redacted in that form.
        if (is_object($value)) {
            $value = json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        }

        if (! is_array($value)) {
            return $value;
        }

        $redactedValue = [];

        foreach ($value as $key => $nestedValue) {
            $redactedValue[$key] = is_string($key) && $this->isFloorName($key)
                ? self::REDACTED
                : $this->redactNestedKeys($nestedValue);
        }

        return $redactedValue;
    }

    /**
     * A name is on the floor when, normalised, it equals a floor name, or
     * when it contains a secret part and is not the name of a moment, a flag
     * or a count (review B5: `two_factor_confirmed_at` records who turned
     * two-factor on, and its value is a time, not a secret).
     */
    private function isFloorName(string $name, ?string $castType = null): bool
    {
        $normalisedName = self::normalise($name);

        if (in_array($normalisedName, $this->floorNames, true)) {
            return true;
        }

        if (self::holdsNoSecret($name, $castType)) {
            return false;
        }

        foreach ($this->secretParts as $secretPart) {
            if (str_contains($normalisedName, $secretPart)) {
                return true;
            }
        }

        return false;
    }

    /** A name ending in `_at` / `_on` (`confirmedAt`), or a date, flag or number cast. */
    private static function holdsNoSecret(string $name, ?string $castType): bool
    {
        if (preg_match('/(_at|_on|At|On)\z/', $name) === 1) {
            return true;
        }

        return $castType !== null && in_array(explode(':', $castType)[0], [
            'date', 'datetime', 'immutable_date', 'immutable_datetime', 'timestamp', 'bool', 'boolean', 'int', 'integer',
        ], true);
    }

    /** Lower case, without `_`, `-`, `.`, spaces, tabs and line breaks. */
    private static function normalise(string $name): string
    {
        return strtolower(str_replace(['_', '-', '.', ' ', "\t", "\n"], '', $name));
    }

    /**
     * @return list<string>
     */
    private static function listOfNames(mixed $configuredNames, string $message): array
    {
        if (! is_array($configuredNames)) {
            throw new InvalidArgumentException($message);
        }

        $names = [];

        foreach ($configuredNames as $configuredName) {
            if (! is_string($configuredName)) {
                throw new InvalidArgumentException($message);
            }

            $names[] = $configuredName;
        }

        return $names;
    }

    private static function lastSegment(string $attributePath): string
    {
        return Str::afterLast($attributePath, '.');
    }

    /**
     * The model that owns the last segment of a relation path, found through
     * relation methods without a query, or null when a segment is not a
     * typed relation.
     */
    private function owningModel(Model $model, string $attributePath): ?Model
    {
        $relationSegments = explode('.', $attributePath);
        array_pop($relationSegments);

        foreach ($relationSegments as $relationSegment) {
            $relationName = self::typedRelationMethod($model, $relationSegment);

            if ($relationName === null) {
                return null;
            }

            $relation = $model->{$relationName}();

            if (! $relation instanceof Relation) {
                return null;
            }

            $model = $relation->getRelated();
        }

        return $model;
    }

    /** The lower-cased cast of a column (case-insensitive lookup), or null. */
    private static function castTypeOf(Model $model, string $column): ?string
    {
        foreach ($model->getCasts() as $castColumn => $castType) {
            if (strtolower($castColumn) === strtolower($column)) {
                return strtolower($castType);
            }
        }

        return null;
    }

    private static function isSecretCast(?string $castType): bool
    {
        return $castType !== null && ($castType === 'hashed' || str_contains($castType, 'encrypt'));
    }

    private static function holdsModels(mixed $value): bool
    {
        if ($value instanceof Model) {
            return true;
        }

        if (! is_iterable($value)) {
            return false;
        }

        foreach ($value as $nestedValue) {
            if (self::holdsModels($nestedValue)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A column name that resolves to a relation method logs the related
     * model whole. Only a method whose declared return type is a Relation
     * counts, so no model method is called to find out.
     */
    private function namesARelation(Model $model, string $column): bool
    {
        return self::typedRelationMethod($model, $column) !== null;
    }

    /**
     * The relation method a path segment names (Spatie's own lookup: as
     * written, snake_case or camelCase), when its declared return type is a
     * Relation. Only such a method is ever called: an untyped or other method
     * (`delete`, `touch`, `replicate`) is never run to find out (review B3).
     */
    private static function typedRelationMethod(Model $model, string $segment): ?string
    {
        $methodName = ChangeDetector::resolveRelationName($model, $segment);

        if (! method_exists($model, $methodName)) {
            return null;
        }

        $returnType = (new ReflectionMethod($model, $methodName))->getReturnType();

        if (! $returnType instanceof ReflectionNamedType || ! is_a($returnType->getName(), Relation::class, true)) {
            return null;
        }

        return $methodName;
    }
}
