<?php

declare(strict_types=1);

namespace Qiick\Audit\Events;

use Qiick\Audit\Erasure\ErasureReport;

/**
 * Reports fleet erasure counts after the audit connection commits.
 *
 * @api
 */
final readonly class SubjectErasedEvent
{
    public function __construct(public ErasureReport $report) {}
}
