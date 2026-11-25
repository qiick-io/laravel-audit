<?php

declare(strict_types=1);

namespace Qiick\Audit\Contracts;

/**
 * What one erasure changed: the number of activity rows it anonymised. A
 * second run over the same subject reports 0.
 *
 * @api
 */
// @pest-arch-ignore-next-line — design §4: readonly value object under Contracts/, not an interface
final readonly class AnonymisationReport
{
    public function __construct(public int $rows) {}
}
