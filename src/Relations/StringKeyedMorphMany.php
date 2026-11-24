<?php

declare(strict_types=1);

namespace Qiick\Audit\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A morphMany whose foreign key is a string column (activity_log's
 * string(64) morph ids) while the parent key may be an integer.
 *
 * Laravel binds an integer parent key as an integer and eager-loads integer
 * keys with whereIntegerInRaw, which PostgreSQL refuses against varchar
 * (`character varying = integer`, A11). This relation binds the parent key
 * and the eager keys as strings.
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends MorphMany<TRelatedModel, TDeclaringModel>
 *
 * @internal
 */
final class StringKeyedMorphMany extends MorphMany
{
    public function getParentKey(): ?string
    {
        return self::stringOrNull(parent::getParentKey());
    }

    /**
     * @param  array<int, TDeclaringModel>  $models
     */
    public function addEagerConstraints(array $models): void
    {
        $parentKeys = [];

        foreach ($models as $model) {
            $parentKey = self::stringOrNull($model->getAttribute($this->localKey));

            if ($parentKey !== null) {
                $parentKeys[] = $parentKey;
            }
        }

        $this->getRelationQuery()
            ->whereIn($this->foreignKey, $parentKeys)
            ->where($this->morphType, $this->morphClass);
    }

    private static function stringOrNull(mixed $key): ?string
    {
        if (is_int($key) || is_string($key)) {
            return (string) $key;
        }

        return null;
    }
}
