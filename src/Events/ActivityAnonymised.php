<?php

declare(strict_types=1);

namespace Qiick\Audit\Events;

use Qiick\Audit\Contracts\MorphRef;

/**
 * Dispatched after every erasure, with the number of rows it anonymised, so
 * the product can report it (design §4).
 *
 * @api
 */
final readonly class ActivityAnonymised
{
    /**
     * @param  list<MorphRef>  $refs
     */
    public function __construct(public int $rows, public array $refs) {}
}
