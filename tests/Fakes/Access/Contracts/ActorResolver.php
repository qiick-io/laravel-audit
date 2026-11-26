<?php

declare(strict_types=1);

namespace Qiick\Access\Contracts;

/** Test double of laravel-access `src/Contracts/ActorResolver.php`. */
interface ActorResolver
{
    public function current(): ActorContext;
}
