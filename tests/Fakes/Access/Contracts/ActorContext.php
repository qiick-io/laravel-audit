<?php

declare(strict_types=1);

namespace Qiick\Access\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/** Test double of laravel-access `src/Contracts/ActorContext.php` (same shape). */
final readonly class ActorContext
{
    public function __construct(
        public ActorKind $kind,
        public ?Authenticatable $user,
        public ?string $credentialUuid = null,
    ) {}
}
