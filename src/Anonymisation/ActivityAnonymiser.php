<?php

declare(strict_types=1);

namespace Qiick\Audit\Anonymisation;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Qiick\Audit\Contracts\AnonymisationReport;
use Qiick\Audit\Contracts\AuditAnonymiser;
use Qiick\Audit\Contracts\ErasureSubject;
use Qiick\Audit\Events\ActivityAnonymised;
use Qiick\Audit\Models\Activity;
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
final class ActivityAnonymiser implements AuditAnonymiser
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
        // A tombstone the patterns match would be scrubbed again on every run.
        if ($this->scrubText($subject->tombstone, [], "\0") !== $subject->tombstone) {
            throw new InvalidArgumentException('The tombstone must not look like an email address or a phone number.');
        }

        $personalKeys = $this->personalKeys();
        $identifiers = $subject->identifiers;
        usort($identifiers, fn (string $left, string $right): int => mb_strlen($right) <=> mb_strlen($left));

        $activityModel = Activity::newConfiguredModel();
        $connection = $activityModel->getConnection();
        $subjectIdColumn = Activity::idColumn('subject_id');
        $causerIdColumn = Activity::idColumn('causer_id');
        $anonymisedRows = 0;

        $connection->transaction(function () use ($activityModel, $connection, $subject, $subjectIdColumn, $causerIdColumn, $personalKeys, $identifiers, &$anonymisedRows): void {
            $connection->table($activityModel->getTable())
                ->select(['id', 'description', 'properties', 'attribute_changes', 'anonymised_at'])
                ->where(function (Builder $query) use ($subject, $subjectIdColumn, $causerIdColumn): void {
                    foreach ($subject->refs as $ref) {
                        $query
                            ->orWhere(fn (Builder $refQuery) => $refQuery->where('subject_type', $ref->type)->where($subjectIdColumn, $ref->id))
                            ->orWhere(fn (Builder $refQuery) => $refQuery->where('causer_type', $ref->type)->where($causerIdColumn, $ref->id));
                    }
                })
                ->lazyById()
                ->each(function (stdClass $row) use ($activityModel, $connection, $subject, $personalKeys, $identifiers, &$anonymisedRows): void {
                    if ($this->anonymiseRow($row, $activityModel, $connection, $subject->tombstone, $personalKeys, $identifiers)) {
                        $anonymisedRows++;
                    }
                });
        });

        event(new ActivityAnonymised($anonymisedRows, $subject->refs));

        return new AnonymisationReport($anonymisedRows);
    }

    /**
     * @param  list<string>  $personalKeys  normalised (SecretAttributeFloor::normalise())
     * @param  list<string>  $identifiers  longest first
     */
    private function anonymiseRow(stdClass $row, Activity $activityModel, Connection $connection, string $tombstone, array $personalKeys, array $identifiers): bool
    {
        $updates = [];

        $description = self::stringColumn($row, 'description');
        $scrubbedDescription = $this->scrubText($description, $identifiers, $tombstone);

        if ($scrubbedDescription !== $description) {
            $updates['description'] = $scrubbedDescription;
        }

        foreach (['properties', 'attribute_changes'] as $jsonColumn) {
            if ($row->{$jsonColumn} === null) {
                continue;
            }

            $decoded = json_decode(self::stringColumn($row, $jsonColumn), true, flags: JSON_THROW_ON_ERROR);
            $scrubbed = $this->scrubValue($decoded, $personalKeys, $identifiers, $tombstone);

            if ($scrubbed !== $decoded) {
                $updates[$jsonColumn] = json_encode($scrubbed, JSON_THROW_ON_ERROR);
            }
        }

        if ($updates === [] && $row->anonymised_at !== null) {
            return false;
        }

        $updates['anonymised_at'] = $activityModel->fromDateTime(Carbon::now('UTC'));

        $connection->table($activityModel->getTable())->where('id', $row->id)->update($updates);

        return true;
    }

    /**
     * @param  list<string>  $personalKeys
     * @param  list<string>  $identifiers
     */
    private function scrubValue(mixed $value, array $personalKeys, array $identifiers, string $tombstone): mixed
    {
        if (is_string($value)) {
            return $this->scrubText($value, $identifiers, $tombstone);
        }

        if (! is_array($value)) {
            return $value;
        }

        $scrubbed = [];

        foreach ($value as $key => $nestedValue) {
            $scrubbed[$key] = is_string($key) && in_array(SecretAttributeFloor::normalise($key), $personalKeys, true)
                ? $tombstone
                : $this->scrubValue($nestedValue, $personalKeys, $identifiers, $tombstone);
        }

        return $scrubbed;
    }

    /**
     * @param  list<string>  $identifiers  longest first
     */
    private function scrubText(string $text, array $identifiers, string $tombstone): string
    {
        foreach ($identifiers as $identifier) {
            // Whole words only: a short name (`Al`) must not corrupt `Alarm`.
            $text = preg_replace('/(?<![\p{L}\p{N}])'.preg_quote($identifier, '/').'(?![\p{L}\p{N}])/iu', $tombstone, $text)
                ?? throw new RuntimeException('An identifier could not be scrubbed.');
        }

        $text = preg_replace(self::EMAIL_PATTERN, $tombstone, $text)
            ?? throw new RuntimeException('Email addresses could not be scrubbed.');

        return preg_replace_callback(
            self::PHONE_PATTERN,
            fn (array $match): string => preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $match[0]) === 1 ? $match[0] : $tombstone,
            $text,
        ) ?? throw new RuntimeException('Phone numbers could not be scrubbed.');
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
