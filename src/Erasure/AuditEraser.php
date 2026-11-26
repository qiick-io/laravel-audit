<?php

declare(strict_types=1);

namespace Qiick\Audit\Erasure;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Qiick\Audit\Contracts\AuditAnonymiser;
use Qiick\Audit\Contracts\ErasureSubject;
use Qiick\Audit\Contracts\FleetAuditAnonymiserContract;
use Qiick\Audit\Contracts\MorphRef;
use Qiick\Audit\Events\SubjectErasedEvent;
use Qiick\Audit\Models\Activity;
use Qiick\Audit\Support\AuditCommitService;
use RuntimeException;
use SensitiveParameter;

/**
 * Erases a person's audit data while retaining evidence in both fleet modes.
 *
 * @api
 */
final class AuditEraser
{
    public const string DELETE = 'delete';

    public const string ANONYMISE = 'anonymise';

    public function __construct(private readonly AuditAnonymiser $anonymiser) {}

    /**
     * Joins the audit connection's transaction if one is open. A real run
     * dispatches SubjectErasedEvent after the outermost commit.
     *
     * @param  list<string>  $publicIds  Principal and API key public UUIDs.
     * @param  list<string>  $identifiers  Values to scrub from free text.
     */
    public function erase(
        #[SensitiveParameter] string $userId,
        #[SensitiveParameter] string $userPublicId,
        #[SensitiveParameter] string $mode,
        #[SensitiveParameter] array $publicIds = [],
        #[SensitiveParameter] array $identifiers = [],
        #[SensitiveParameter] ?string $organisationId = null,
        bool $dryRun = false,
    ): ErasureReport {
        if (! in_array($mode, [self::DELETE, self::ANONYMISE], true)) {
            throw new InvalidArgumentException('The erasure mode is invalid.');
        }

        $publicIds = array_map(self::uuid(...), [$userPublicId, ...$publicIds]);
        $organisationId = $organisationId === null ? null : self::uuid($organisationId);
        $tombstone = config('audit.erasure_tombstone');

        if (! is_string($tombstone)) {
            throw new LogicException('The audit erasure tombstone must be a string.');
        }

        $subject = new ErasureSubject($this->userRefs($userId), $tombstone, [...$identifiers, ...$publicIds]);
        $anonymiser = $this->anonymiser;

        if (! $anonymiser instanceof FleetAuditAnonymiserContract) {
            if ($organisationId !== null) {
                throw new OrganisationScopeUnsupportedException('The bound audit anonymiser does not support organisation-scoped erasure.');
            }

            throw new LogicException('The bound audit anonymiser does not support fleet erasure.');
        }

        $activityModel = Activity::newConfiguredModel();
        $connection = $activityModel->getConnection();

        try {
            return $connection->transaction(function () use ($activityModel, $connection, $anonymiser, $subject, $publicIds, $organisationId, $mode, $dryRun): ErasureReport {
                if ($organisationId !== null && ! $connection->getSchemaBuilder()->hasColumn($activityModel->getTable(), 'organisation_id')) {
                    throw new OrganisationScopeUnsupportedException('The configured audit table cannot filter by organisation.');
                }

                $report = $anonymiser->anonymiseForErasure($activityModel, $subject, $publicIds, $organisationId, $mode, $dryRun);

                if (! $dryRun) {
                    AuditCommitService::afterCommit($connection, fn () => event(new SubjectErasedEvent($report)));
                }

                return $report;
            });
        } catch (QueryException) {
            // Laravel includes bound values in query exceptions and their
            // messages. Do not attach the original exception to the failure.
            throw new RuntimeException('The audit erasure could not complete a database operation.');
        }
    }

    private static function uuid(#[SensitiveParameter] mixed $value): string
    {
        if (! is_string($value) || ! Str::isUuid($value)) {
            throw new InvalidArgumentException('Erasure public and organisation identifiers must be UUIDs.');
        }

        return strtolower($value);
    }

    /** @return list<MorphRef> */
    private function userRefs(#[SensitiveParameter] string $userId): array
    {
        $model = config('auth.providers.users.model');

        if (! is_string($model) || ! is_subclass_of($model, Model::class)) {
            throw new LogicException('Audit erasure requires an Eloquent model in auth.providers.users.model.');
        }

        if (trim($userId) === '') {
            throw new InvalidArgumentException('The erasure user key must be a non-empty string.');
        }

        // The user may already have been deleted. Match both the current
        // morph alias and the class name stored before a map was adopted.
        $refs = [];

        foreach (array_unique([(new $model)->getMorphClass(), $model]) as $morphType) {
            $refs[] = new MorphRef($morphType, $userId);
        }

        return $refs;
    }
}
