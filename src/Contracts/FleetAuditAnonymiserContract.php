<?php

declare(strict_types=1);

namespace Qiick\Audit\Contracts;

use Qiick\Audit\Erasure\ErasureReport;
use Qiick\Audit\Models\Activity;

/**
 * The capabilities a custom anonymiser must provide for the fleet eraser.
 *
 * @api
 */
interface FleetAuditAnonymiserContract extends AuditAnonymiser
{
    /**
     * Runs inside the eraser's transaction on the configured activity model's
     * connection. A dry run predicts the same counts without writing. Apply
     * the organisation filter when given and leave event dispatch to the eraser.
     *
     * @param  list<string>  $publicIds  The person's user, principal and credential UUIDs.
     */
    public function anonymiseForErasure(
        Activity $activityModel,
        ErasureSubject $subject,
        array $publicIds,
        ?string $organisationId,
        string $mode,
        bool $isDryRun,
    ): ErasureReport;
}
