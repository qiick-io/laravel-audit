<?php

declare(strict_types=1);

namespace Qiick\Audit\Contracts;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * One morph reference to a model, as activity_log stores it: the morph type
 * from getMorphClass() (the morph map alias when one is set, A7) and the key
 * as a string.
 *
 * @api
 */
// @pest-arch-ignore-next-line — design §4: readonly value object under Contracts/, not an interface
final readonly class MorphRef
{
    public function __construct(public string $type, public string $id)
    {
        if ($type === '') {
            throw new InvalidArgumentException('A morph ref needs a type.');
        }

        if ($id === '' || strlen($id) > 64) {
            throw new InvalidArgumentException('A morph ref id must have 1 to 64 characters.');
        }
    }

    public static function of(Model $model): self
    {
        $key = $model->getKey();

        if (! is_int($key) && ! is_string($key)) {
            throw new InvalidArgumentException('A morph ref needs a model with an integer or string key.');
        }

        return new self($model->getMorphClass(), (string) $key);
    }
}
