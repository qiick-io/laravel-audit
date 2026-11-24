<?php

declare(strict_types=1);

namespace Qiick\Audit\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;
use ReflectionMethod;
use ReflectionNamedType;
use Spatie\Activitylog\Support\ChangeDetector;

/**
 * Decides which attribute paths the LogsActivity trait may never log
 * (design §4 "trait secret floor", A1).
 *
 * An attribute is secret when its name is on the built-in floor or on
 * `audit.sensitive_attributes`, or when the model that owns it casts it as
 * encrypted* or hashed. Names compare case-insensitively. The floor covers
 * every path Spatie can log:
 * - `settings->secret` is secret when `settings` is, or when any JSON key on
 *   the path is a floor name;
 * - `owner.password` is secret when the attribute is secret on the related
 *   model, found through the relation methods without a query. A segment that
 *   is not a relation fails closed;
 * - a name that is a relation (`owner`) would log the related model whole,
 *   so it is dropped, and withoutSecretValues() drops any resolved value
 *   that holds a model and every floor key nested in a JSON value.
 *
 * @internal
 */
final class SecretAttributeFloor
{
    /** @var list<string> normalised floor names (see normalise()) */
    private readonly array $floorNames;

    /**
     * @param  list<string>  $configuredAttributes  audit.sensitive_attributes
     */
    public function __construct(array $configuredAttributes)
    {
        $this->floorNames = array_map(self::normalise(...), [...self::builtInAttributes(), ...$configuredAttributes]);
    }

    /**
     * Parts of a name that make it secret wherever they appear, in their
     * normalised form (review B4): `userPassword`, `client_secret`,
     * `accessToken`, `X-Api-Key`, `twoFactorRecoveryCodes`, `remember-token`.
     *
     * @return list<string>
     */
    public static function builtInSecretParts(): array
    {
        return ['password', 'secret', 'token', 'privatekey', 'apikey', 'twofactor', 'recoverycode', 'remember'];
    }

    /**
     * The floor a product can add to but never remove: the config list is
     * merged with it, so an emptied config keeps these.
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
        ];
    }

    /**
     * Builds the floor from `audit.sensitive_attributes`. A value that is not
     * a list of strings is a misconfiguration of a security control, so it
     * fails loudly instead of logging with a partial floor.
     */
    public static function fromConfig(mixed $configuredAttributes): self
    {
        if (! is_array($configuredAttributes)) {
            throw new InvalidArgumentException('audit.sensitive_attributes must be a list of attribute names.');
        }

        $attributeNames = [];

        foreach ($configuredAttributes as $attributeName) {
            if (! is_string($attributeName)) {
                throw new InvalidArgumentException('audit.sensitive_attributes must be a list of attribute names.');
            }

            $attributeNames[] = $attributeName;
        }

        return new self($attributeNames);
    }

    /**
     * @param  array<mixed>  $attributePaths  what Spatie would log
     * @return list<string> the paths that are not secret, in their order
     */
    public function withoutSecrets(Model $model, array $attributePaths): array
    {
        $loggablePaths = [];

        foreach ($attributePaths as $attributePath) {
            if (! is_string($attributePath)) {
                throw new InvalidArgumentException('An attribute to log must be named by a string.');
            }

            if (! $this->isSecret($model, $attributePath)) {
                $loggablePaths[] = $attributePath;
            }
        }

        return $loggablePaths;
    }

    public function isSecret(Model $model, string $attributePath): bool
    {
        $relationSegments = explode('.', $attributePath);
        $attribute = array_pop($relationSegments);
        $owningModel = $model;

        foreach ($relationSegments as $relationSegment) {
            $owningModel = $this->relatedModel($owningModel, $relationSegment);

            if ($owningModel === null) {
                return true;
            }
        }

        $jsonKeys = explode('->', $attribute);
        $column = $jsonKeys[0];

        if ($this->isCastAsSecret($owningModel, $column) || $this->namesARelation($owningModel, $column)) {
            return true;
        }

        return $this->namesAFloorKey($jsonKeys);
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
     * A name is on the floor when, once normalised, it equals a floor name or
     * contains a secret part, so every spelling of a key matches: snake_case,
     * camelCase, kebab-case, dotted or spaced, in any letter case.
     */
    private function isFloorName(string $name): bool
    {
        $normalisedName = self::normalise($name);

        if (in_array($normalisedName, $this->floorNames, true)) {
            return true;
        }

        foreach (self::builtInSecretParts() as $secretPart) {
            if (str_contains($normalisedName, $secretPart)) {
                return true;
            }
        }

        return false;
    }

    /** Lower case, without `_`, `-`, `.`, spaces, tabs and line breaks. */
    private static function normalise(string $name): string
    {
        return strtolower(str_replace(['_', '-', '.', ' ', "\t", "\n"], '', $name));
    }

    /**
     * The values Spatie resolved for the loggable paths, made safe to store
     * (review B1, B2):
     * - a value that is a model or a collection of models (a relation logged
     *   by name, or `owner.owner`) is dropped whole: its toArray() would carry
     *   the related model's secrets, decrypted;
     * - arrays and array-like values (JSON casts, collections, array objects)
     *   lose every key on the floor, at any depth.
     *
     * @param  array<array-key, mixed>  $changes  attribute path => value
     * @return array<array-key, mixed>
     */
    public function withoutSecretValues(array $changes): array
    {
        $safeChanges = [];

        foreach ($changes as $attributePath => $value) {
            if (self::holdsModels($value)) {
                continue;
            }

            $safeChanges[$attributePath] = $this->withoutSecretKeys($value);
        }

        return $safeChanges;
    }

    private function withoutSecretKeys(mixed $value): mixed
    {
        // Collections, array objects and value objects reach the log as
        // their JSON form, so they are scrubbed in that form.
        if (is_object($value)) {
            $value = json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        }

        if (! is_array($value)) {
            return $value;
        }

        $safeValue = [];

        foreach ($value as $key => $nestedValue) {
            if (is_string($key) && $this->isFloorName($key)) {
                continue;
            }

            $safeValue[$key] = $this->withoutSecretKeys($nestedValue);
        }

        return $safeValue;
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
     * A column name that resolves to a relation method (Spatie's own lookup:
     * as written, snake_case or camelCase) logs the related model whole, so
     * it is dropped. Only a method whose declared return type is a Relation
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

    private function relatedModel(Model $model, string $relationSegment): ?Model
    {
        $relationName = self::typedRelationMethod($model, $relationSegment);

        if ($relationName === null) {
            return null;
        }

        $relation = $model->{$relationName}();

        return $relation instanceof Relation ? $relation->getRelated() : null;
    }

    private function isCastAsSecret(Model $model, string $column): bool
    {
        foreach ($model->getCasts() as $castColumn => $castType) {
            if (strtolower($castColumn) !== strtolower($column)) {
                continue;
            }

            $castType = strtolower($castType);

            return $castType === 'hashed' || str_contains($castType, 'encrypt');
        }

        return false;
    }
}
