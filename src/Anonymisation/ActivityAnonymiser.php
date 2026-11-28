<?php

declare(strict_types=1);

namespace Qiick\Audit\Anonymisation;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Qiick\Audit\Contracts\AnonymisationReport;
use Qiick\Audit\Contracts\ErasureSubject;
use Qiick\Audit\Contracts\FleetAuditAnonymiserContract;
use Qiick\Audit\Erasure\ErasureReport;
use Qiick\Audit\Events\ActivityAnonymisedEvent;
use Qiick\Audit\Models\Activity;
use Qiick\Audit\Support\AuditCommitService;
use Qiick\Audit\Support\SecretAttributeFloor;
use RuntimeException;
use stdClass;
use UnexpectedValueException;

/**
 * The default AuditAnonymiser (design §5).
 *
 * For every row whose causer or subject is one of the subject's morph refs
 * (compared through the stored morph type, so a morph map is honoured, A7):
 * - every personal key in `properties` and `attribute_changes`, at any depth
 *   and in any spelling (`firstName`, `last-name`, `Phone Number`, compared
 *   like the secret floor), gets the tombstone: ip, ip_address, user_agent,
 *   email, username, name, first_name, last_name and `audit.personal_keys`;
 * - every string, in those JSON columns and in `description`, has the
 *   subject's identifiers replaced by the tombstone as whole words, then
 *   every email address and phone-like sequence (A14);
 * - `anonymised_at` is set.
 *
 * It never deletes a row and never changes the event, the log, the
 * timestamps or the morphs (A8). It is idempotent: a row whose content does
 * not change and that is already marked is skipped, so a second run reports
 * 0 rows, while a row anonymised earlier for someone else is still scrubbed
 * for this subject. Rows are read lazily in chunks by id, inside one
 * transaction.
 *
 * @api The default AuditAnonymiser binding.
 */
final class ActivityAnonymiser implements FleetAuditAnonymiserContract
{
    /** Email addresses, the most common PII in free text. */
    private const string EMAIL_PATTERN = '/[A-Z0-9._%+-]+@[A-Z0-9-]+(?:\.[A-Z0-9-]+)*\.[A-Z]{2,}/i';

    /**
     * Phone-like sequences: 8 to 15 digits (E.164), with an optional `+` and
     * spaces, dots, dashes or brackets between them. A sequence glued to a
     * letter, a slash, a colon or a dash (UUIDs, ULIDs, hashes, URLs, times)
     * or to a decimal point is not a phone number; dates are kept apart in
     * scrubText().
     */
    private const string PHONE_PATTERN = '/(?<![\w\/:-]|\d\.)\+?\d(?:[ ().-]{0,2}\d){7,14}(?![\w\/:-]|\.\d)/u';

    /**
     * The keys every erasure scrubs, before `audit.personal_keys`.
     *
     * @return list<string>
     */
    public static function builtInPersonalKeys(): array
    {
        return ['ip', 'ip_address', 'user_agent', 'email', 'username', 'name', 'first_name', 'last_name'];
    }

    public function anonymise(ErasureSubject $subject): AnonymisationReport
    {
        $activityModel = Activity::newConfiguredModel();
        $connection = $activityModel->getConnection();

        return $connection->transaction(function () use ($activityModel, $connection, $subject): AnonymisationReport {
            $counts = $this->scrubRows($activityModel, $subject);
            AuditCommitService::afterCommit($connection, fn () => event(new ActivityAnonymisedEvent($counts['anonymised'], $subject->refs)));

            return new AnonymisationReport($counts['anonymised']);
        });
    }

    public function anonymiseForErasure(
        Activity $activityModel,
        ErasureSubject $subject,
        array $publicIds,
        ?string $organisationId,
        string $mode,
        bool $isDryRun,
    ): ErasureReport {
        $counts = $this->scrubRows($activityModel, $subject, $publicIds, $organisationId, $isDryRun);
        $table = $activityModel->getTable();

        return new ErasureReport('qiick/laravel-audit', $mode, $isDryRun, [
            $table => self::storeCounts($counts['anonymised'], $counts['kept']),
            'cleartext:'.$table.'.'.Activity::idColumn('subject_id') => self::storeCounts(0, $counts['subjectIds']),
            'cleartext:'.$table.'.'.Activity::idColumn('causer_id') => self::storeCounts(0, $counts['causerIds']),
        ]);
    }

    /** @return array{deleted: int, anonymised: int, kept: int, reason: ?string} */
    private static function storeCounts(int $anonymised, int $kept): array
    {
        return [
            'deleted' => 0,
            'anonymised' => $anonymised,
            'kept' => $kept,
            'reason' => $kept > 0 ? 'legal_obligation' : null,
        ];
    }

    /**
     * The same scan plans and applies scrubbing, so the dry run predicts the
     * real run without issuing any write. Real runs lock by primary key to
     * avoid InnoDB range locks during the candidate scan at REPEATABLE READ.
     *
     * @param  list<string>  $publicIds  Empty for the original anonymiser API.
     * @return array{anonymised: int, kept: int, subjectIds: int, causerIds: int}
     */
    private function scrubRows(
        Activity $activityModel,
        ErasureSubject $subject,
        array $publicIds = [],
        ?string $organisationId = null,
        bool $isDryRun = false,
    ): array {
        // A tombstone the patterns match would be scrubbed again on every run.
        if ($this->scrubText($subject->tombstone, [], "\0") !== $subject->tombstone) {
            throw new InvalidArgumentException('The tombstone must not look like an email address or a phone number.');
        }

        $personalKeys = $this->personalKeys();
        $identifiers = $subject->identifiers;
        usort($identifiers, fn (string $left, string $right): int => mb_strlen($right) <=> mb_strlen($left));

        $connection = $activityModel->getConnection();
        $table = $activityModel->getTable();
        $subjectIdColumn = Activity::idColumn('subject_id');
        $causerIdColumn = Activity::idColumn('causer_id');
        $query = $connection->table($table)
            ->select(['id', 'description', 'properties', 'attribute_changes', 'anonymised_at', 'subject_type', 'causer_type', $subjectIdColumn, $causerIdColumn])
            ->where(function (Builder $query) use ($subject, $subjectIdColumn, $causerIdColumn, $publicIds): void {
                foreach ($subject->refs as $ref) {
                    $query
                        ->orWhere(fn (Builder $refQuery) => $refQuery->where('subject_type', $ref->type)->where($subjectIdColumn, $ref->id))
                        ->orWhere(fn (Builder $refQuery) => $refQuery->where('causer_type', $ref->type)->where($causerIdColumn, $ref->id));
                }

                if ($publicIds !== []) {
                    // Reserved attribution also links a user who created a
                    // key used by another causer. JSON selectors let Laravel
                    // choose the appropriate SQL for each database engine.
                    foreach ([$subjectIdColumn, $causerIdColumn, 'properties->api_key_id', 'properties->api_key_created_by'] as $selector) {
                        $query->orWhereIn(new LowercaseAuditColumnExpression($selector), $publicIds);
                    }
                }
            });

        if ($organisationId !== null) {
            $query->where('organisation_id', $organisationId);
        }

        $candidates = $isDryRun ? $query : (clone $query)->select('id');
        $counts = ['anonymised' => 0, 'kept' => 0, 'subjectIds' => 0, 'causerIds' => 0];

        foreach ($candidates->lazyById() as $row) {
            if (! $isDryRun) {
                // Read the current values and recheck ownership after the scan.
                // Retention may have removed the row or another erasure scrubbed it.
                $row = (clone $query)->where('id', $row->id)->lockForUpdate()->first();

                if ($row === null) {
                    continue;
                }
            }

            $properties = self::jsonColumn($row, 'properties');
            $hasSubjectRef = self::hasMorph($row->subject_type, $row->{$subjectIdColumn}, $subject, $publicIds);
            $hasCauserRef = self::hasMorph($row->causer_type, $row->{$causerIdColumn}, $subject, $publicIds);
            // The older API scrubs every row selected by its original
            // database predicates. Fleet runs verify exact ownership because
            // a case-insensitive collation can return broader matches.
            $hasMorph = $publicIds === [] || $hasSubjectRef || $hasCauserRef;
            $hasCredential = self::hasPublicId(data_get($properties, 'api_key_id'), $publicIds);
            $hasCreator = self::hasPublicId(data_get($properties, 'api_key_created_by'), $publicIds);
            $isOwnActivity = $hasMorph || $hasCredential;

            if (! $isOwnActivity && ! $hasCreator) {
                continue;
            }

            $counts['kept']++;
            $counts['subjectIds'] += $hasSubjectRef ? 1 : 0;
            $counts['causerIds'] += $hasCauserRef ? 1 : 0;
            // A row the person only caused about another subject is evidence
            // about that subject: `properties` (the request's context) lose
            // every personal key; the subject's `attribute_changes` keep their
            // keys and only the person's identifiers (including encoded forms
            // and numeric phones) are replaced — never generic email/phone
            // patterns, which would wipe the other subject's contacts
            // (decision 2026-11-27). Acting through their API key is still
            // "only caused": the credential links them as actor, not as subject.
            $isCauserOnly = ! $hasSubjectRef
                && $row->subject_type !== null
                && ($hasCauserRef || $hasCredential);
            $propertyKeys = $isOwnActivity ? $personalKeys : [];
            $updates = $this->rowUpdates(
                $row,
                $properties,
                $subject->tombstone,
                $propertyKeys,
                $isCauserOnly ? [] : $propertyKeys,
                $identifiers,
                shouldScrubPropertyPatterns: $isOwnActivity,
                shouldScrubChangePatterns: $isOwnActivity && ! $isCauserOnly,
                isOwnActivity: $isOwnActivity,
                isAlreadyAnonymised: $row->anonymised_at !== null,
            );

            if ($updates === [] && $row->anonymised_at !== null) {
                continue;
            }

            if (! $isDryRun) {
                $updates['anonymised_at'] = $activityModel->fromDateTime(Carbon::now('UTC'));
                $connection->table($table)->where('id', $row->id)->update($updates);
            }

            $counts['anonymised']++;
        }

        return $counts;
    }

    /** @param list<string> $publicIds */
    private static function hasMorph(mixed $type, mixed $id, ErasureSubject $subject, array $publicIds): bool
    {
        $refs = array_map(fn ($ref): array => [$ref->type, $ref->id], $subject->refs);

        return in_array([$type, $id], $refs, true) || self::hasPublicId($id, $publicIds);
    }

    /** @param list<string> $publicIds */
    private static function hasPublicId(mixed $value, array $publicIds): bool
    {
        return is_string($value) && in_array(strtolower($value), $publicIds, true);
    }

    /**
     * Attribution alone can link somebody else's activity to a creator.
     * Scrub the supplied identifiers there without blanking the other
     * person's names, email addresses, IP address or user agent.
     *
     * @param  list<string>  $propertyKeys  scrubbed in `properties`, normalised (SecretAttributeFloor::normalise())
     * @param  list<string>  $changeKeys  scrubbed in `attribute_changes`, normalised
     * @param  list<string>  $identifiers  longest first
     * @return array<string, string>
     */
    private function rowUpdates(
        stdClass $row,
        mixed $properties,
        string $tombstone,
        array $propertyKeys,
        array $changeKeys,
        array $identifiers,
        bool $shouldScrubPropertyPatterns,
        bool $shouldScrubChangePatterns,
        bool $isOwnActivity,
        bool $isAlreadyAnonymised,
    ): array {
        $updates = [];
        $skipTombstonePrefixedIdentifiers = $isAlreadyAnonymised;

        $description = self::stringColumn($row, 'description');
        $scrubbedDescription = $this->scrubText(
            $description,
            $identifiers,
            $tombstone,
            $shouldScrubPropertyPatterns,
            $skipTombstonePrefixedIdentifiers,
        );

        if ($scrubbedDescription !== $description) {
            $updates['description'] = $scrubbedDescription;
        }

        // The request's credential prefix names the person even when the row
        // only links them as causer (no matching api_key_id in publicIds).
        if ($isOwnActivity) {
            $propertyKeys = [...$propertyKeys, 'apikeyprefix'];
        }

        $scrubbedProperties = $this->scrubValue(
            $properties,
            $propertyKeys,
            $identifiers,
            $tombstone,
            $shouldScrubPropertyPatterns,
            $skipTombstonePrefixedIdentifiers,
        );

        if (self::jsonPayload($scrubbedProperties) !== self::jsonPayload($properties)) {
            $updates['properties'] = self::jsonPayload($scrubbedProperties);
        }

        $changes = self::jsonColumn($row, 'attribute_changes');
        $scrubbedChanges = $this->scrubValue(
            $changes,
            $changeKeys,
            $identifiers,
            $tombstone,
            $shouldScrubChangePatterns,
            $skipTombstonePrefixedIdentifiers,
        );

        if (self::jsonPayload($scrubbedChanges) !== self::jsonPayload($changes)) {
            $updates['attribute_changes'] = self::jsonPayload($scrubbedChanges);
        }

        return $updates;
    }

    /**
     * Decode JSON as objects so empty `{}` stays an object through a rewrite
     * (associative decode would turn it into `[]` on encode).
     */
    private static function jsonColumn(stdClass $row, string $column): mixed
    {
        return $row->{$column} === null
            ? null
            : json_decode(self::stringColumn($row, $column), flags: JSON_THROW_ON_ERROR);
    }

    private static function jsonPayload(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  list<string>  $personalKeys
     * @param  list<string>  $identifiers
     */
    private function scrubValue(
        mixed $value,
        array $personalKeys,
        array $identifiers,
        string $tombstone,
        bool $shouldScrubPatterns,
        bool $skipTombstonePrefixedIdentifiers,
    ): mixed {
        if (is_string($value)) {
            return $this->scrubText($value, $identifiers, $tombstone, $shouldScrubPatterns, $skipTombstonePrefixedIdentifiers);
        }

        if (is_int($value) || is_float($value)) {
            return $this->scalarMatchesIdentifier((string) $value, $identifiers, $skipTombstonePrefixedIdentifiers, $tombstone)
                ? $tombstone
                : $value;
        }

        if ($value instanceof stdClass) {
            $scrubbed = new stdClass;

            // get_object_vars yields int keys for {"42":…}; scrubKeyOrValue needs a string.
            foreach (get_object_vars($value) as $key => $nestedValue) {
                $keyName = is_string($key) ? $key : (string) $key;

                // A map keyed by the person (UUID / id) is their data: drop the key
                // so the identifier does not remain in the JSON shape.
                if ($this->scalarMatchesIdentifier($keyName, $identifiers, $skipTombstonePrefixedIdentifiers, $tombstone)) {
                    continue;
                }

                $scrubbed->{$keyName} = $this->scrubKeyOrValue(
                    $keyName,
                    $nestedValue,
                    $personalKeys,
                    $identifiers,
                    $tombstone,
                    $shouldScrubPatterns,
                    $skipTombstonePrefixedIdentifiers,
                );
            }

            return $scrubbed;
        }

        if (! is_array($value)) {
            return $value;
        }

        $scrubbed = [];

        foreach ($value as $key => $nestedValue) {
            $scrubbed[$key] = is_string($key)
                ? $this->scrubKeyOrValue(
                    $key,
                    $nestedValue,
                    $personalKeys,
                    $identifiers,
                    $tombstone,
                    $shouldScrubPatterns,
                    $skipTombstonePrefixedIdentifiers,
                )
                : $this->scrubValue($nestedValue, $personalKeys, $identifiers, $tombstone, $shouldScrubPatterns, $skipTombstonePrefixedIdentifiers);
        }

        return $scrubbed;
    }

    /**
     * @param  list<string>  $personalKeys
     * @param  list<string>  $identifiers
     */
    private function scrubKeyOrValue(
        string $key,
        mixed $nestedValue,
        array $personalKeys,
        array $identifiers,
        string $tombstone,
        bool $shouldScrubPatterns,
        bool $skipTombstonePrefixedIdentifiers,
    ): mixed {
        if (in_array(SecretAttributeFloor::normalise($key), $personalKeys, true)) {
            return $tombstone;
        }

        // A map keyed by the person's public id is their data, not the subject's.
        if ($this->scalarMatchesIdentifier($key, $identifiers, $skipTombstonePrefixedIdentifiers, $tombstone)) {
            return $tombstone;
        }

        return $this->scrubValue($nestedValue, $personalKeys, $identifiers, $tombstone, $shouldScrubPatterns, $skipTombstonePrefixedIdentifiers);
    }

    /**
     * @param  list<string>  $identifiers  longest first
     */
    private function scrubText(
        string $text,
        array $identifiers,
        string $tombstone,
        bool $shouldScrubPatterns = true,
        bool $skipTombstonePrefixedIdentifiers = false,
    ): string {
        // Treat the configured tombstone as text, even if it contains "$1".
        $replacement = static fn (): string => $tombstone;

        foreach ($this->identifierForms($identifiers) as $identifier) {
            if ($skipTombstonePrefixedIdentifiers && self::startsWithTombstone($identifier, $tombstone)) {
                continue;
            }

            // Whole words only: a short name (`Al`) must not corrupt `Alarm`.
            $text = preg_replace_callback(ErasureSubject::identifierPattern($identifier), $replacement, $text)
                ?? throw new RuntimeException('An identifier could not be scrubbed.');
        }

        if (! $shouldScrubPatterns) {
            return $text;
        }

        $text = preg_replace_callback(self::EMAIL_PATTERN, $replacement, $text)
            ?? throw new RuntimeException('Email addresses could not be scrubbed.');

        return preg_replace_callback(
            self::PHONE_PATTERN,
            fn (array $match): string => preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $match[0]) === 1 ? $match[0] : $tombstone,
            $text,
        ) ?? throw new RuntimeException('Phone numbers could not be scrubbed.');
    }

    /**
     * @param  list<string>  $identifiers
     * @return list<string> longest first, with URL- and unicode-encoded forms
     */
    private function identifierForms(array $identifiers): array
    {
        $forms = [];

        foreach ($identifiers as $identifier) {
            $forms[] = $identifier;

            $urlEncoded = rawurlencode($identifier);

            if ($urlEncoded !== $identifier) {
                $forms[] = $urlEncoded;
            }

            if (str_contains($identifier, '@')) {
                $forms[] = str_replace('@', '\\u0040', $identifier);
            }

            // Spaces as \\u0020 (Ada\\u0020Lovelace in JSON text). Non-ASCII letters are
            // passed as identifiers in their literal form by the caller when needed.
            if (str_contains($identifier, ' ')) {
                $forms[] = str_replace(' ', '\\u0020', $identifier);
            }
        }

        usort($forms, fn (string $left, string $right): int => mb_strlen($right) <=> mb_strlen($left));

        return $forms;
    }

    /**
     * @param  list<string>  $identifiers
     */
    private function scalarMatchesIdentifier(
        string $value,
        array $identifiers,
        bool $skipTombstonePrefixedIdentifiers,
        string $tombstone,
    ): bool {
        foreach ($this->identifierForms($identifiers) as $identifier) {
            if ($skipTombstonePrefixedIdentifiers && self::startsWithTombstone($identifier, $tombstone)) {
                continue;
            }

            if (strcasecmp($value, $identifier) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function startsWithTombstone(string $identifier, string $tombstone): bool
    {
        return str_starts_with(mb_strtolower($identifier), mb_strtolower($tombstone))
            && (mb_strlen($identifier) === mb_strlen($tombstone)
                || ! preg_match('/\A[\p{L}\p{N}]/u', mb_substr($identifier, mb_strlen($tombstone))));
    }

    private static function stringColumn(stdClass $row, string $column): string
    {
        $value = $row->{$column};

        if (! is_string($value)) {
            throw new UnexpectedValueException("activity_log.{$column} must be read as a string.");
        }

        return $value;
    }

    /**
     * @return list<string> normalised
     */
    private function personalKeys(): array
    {
        $configuredKeys = config('audit.personal_keys');

        if (! is_array($configuredKeys)) {
            throw new InvalidArgumentException('audit.personal_keys must be a list of property keys.');
        }

        $personalKeys = array_map(SecretAttributeFloor::normalise(...), self::builtInPersonalKeys());

        foreach ($configuredKeys as $configuredKey) {
            if (! is_string($configuredKey)) {
                throw new InvalidArgumentException('audit.personal_keys must be a list of property keys.');
            }

            $personalKeys[] = SecretAttributeFloor::normalise($configuredKey);
        }

        return $personalKeys;
    }
}
