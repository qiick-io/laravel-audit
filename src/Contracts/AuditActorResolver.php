<?php

declare(strict_types=1);

namespace Qiick\Audit\Contracts;

/**
 * Who acted, as bound by the server after it verified a credential. Never
 * request input (design §4). The default is RequestAttributeActorResolver.
 *
 * @api
 */
interface AuditActorResolver
{
    public function current(): AuditActor;
}
