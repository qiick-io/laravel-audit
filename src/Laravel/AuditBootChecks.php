<?php

declare(strict_types=1);

namespace Qiick\Audit\Laravel;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Qiick\Audit\Actions\CleanActivityLogAction;
use Qiick\Audit\Actions\LogActivityAction;
use Qiick\Audit\Models\Activity;
use RuntimeException;

/**
 * The checks that stop the application from booting (design §4):
 *
 * - the activity model, the logging action and the clean action are the
 *   package's, or extend them: the public_id, the string-safe scopes, the
 *   server-side attribution and the retention floors live there;
 * - `app.timezone` is UTC (A13);
 * - in production, the log is on and written before the response (A9: OWASP,
 *   security events must not be lost or turned off by one env variable).
 *
 * @internal
 */
final readonly class AuditBootChecks
{
    public function __construct(
        private ConfigRepository $config,
        private bool $isProduction,
    ) {}

    public function assert(): void
    {
        $activityModel = $this->config->get('activitylog.activity_model');

        if (! is_string($activityModel) || ! is_a($activityModel, Activity::class, true)) {
            throw new RuntimeException('activitylog.activity_model must extend '.Activity::class.'.');
        }

        $logActivityAction = $this->config->get('activitylog.actions.log_activity');

        if (! is_string($logActivityAction) || ! is_a($logActivityAction, LogActivityAction::class, true)) {
            throw new RuntimeException('activitylog.actions.log_activity must extend '.LogActivityAction::class.': it writes the server-side attribution.');
        }

        $cleanActivityLogAction = $this->config->get('activitylog.actions.clean_log');

        if (! is_string($cleanActivityLogAction) || ! is_a($cleanActivityLogAction, CleanActivityLogAction::class, true)) {
            throw new RuntimeException('activitylog.actions.clean_log must extend '.CleanActivityLogAction::class.': it applies the two retention floors.');
        }

        if ($this->config->get('app.timezone') !== 'UTC') {
            throw new RuntimeException('app.timezone must be UTC: audit timestamps are stored and exported in UTC.');
        }

        if (! $this->isProduction) {
            return;
        }

        if ($this->config->get('activitylog.enabled') !== true) {
            throw new RuntimeException('activitylog.enabled must be true in production: security events must not be turned off.');
        }

        if ($this->config->get('activitylog.buffer.enabled') !== false) {
            throw new RuntimeException('activitylog.buffer.enabled must be false in production: buffered security events are lost when the process dies.');
        }
    }
}
