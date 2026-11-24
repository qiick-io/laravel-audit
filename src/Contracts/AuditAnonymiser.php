<?php

declare(strict_types=1);

namespace Qiick\Audit\Contracts;

/**
 * GDPR erasure of audit data about one data subject (design §5). The
 * product's erasure job calls it in the same transaction as the user
 * tombstoning, or after it, never alone.
 *
 * @api
 */
interface AuditAnonymiser
{
    public function anonymise(ErasureSubject $subject): AnonymisationReport;
}
