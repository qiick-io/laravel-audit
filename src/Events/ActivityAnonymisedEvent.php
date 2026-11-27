<?php

declare(strict_types=1);

namespace Qiick\Audit\Events;

use Qiick\Audit\Contracts\MorphRef;

/**
 * Dispatched after every erasure, with the number of rows it anonymised, so
 * the product can report it (design §4). It is dispatched after the outermost
 * transaction commits, so a rolled-back erasure is never reported.
 *
 * @api
 */
final readonly class ActivityAnonymisedEvent
{
    /**
     * @param  list<MorphRef>  $refs
     */
    public function __construct(public int $rows, public array $refs) {}
}
