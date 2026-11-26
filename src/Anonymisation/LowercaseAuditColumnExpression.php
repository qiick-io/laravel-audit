<?php

declare(strict_types=1);

namespace Qiick\Audit\Anonymisation;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;

/**
 * Compares UUIDs case-insensitively, including JSON paths with a binary collation.
 *
 * @internal
 */
final readonly class LowercaseAuditColumnExpression implements Expression
{
    public function __construct(private string $column) {}

    public function getValue(Grammar $grammar): string
    {
        return 'lower('.$grammar->wrap($this->column).')';
    }
}
