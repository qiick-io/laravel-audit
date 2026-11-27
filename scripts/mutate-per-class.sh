#!/usr/bin/env bash
# Enforce pest --mutate --min=95 per class (no --covered-only, never || true,
# never @pest-mutate-ignore). One invocation per class so a weak class cannot
# hide behind a high overall score.
#
# CI splits the list into parallel shards: MUTATE_SHARD=i MUTATE_SHARDS=n runs
# the classes at positions i, i+n, i+2n, … (1-based). Without them, every
# class runs, as locally.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

# Every class with behaviour is a target; populated as feature PRs land.
# Not listed because pest creates no mutant for them: NullOrganisationResolver
# (returns null), Concerns\CausesActivity and Concerns\HasActivity (one-line
# delegations to StringKeyedMorphMany and LogsActivity, which are listed).
CLASSES=(
  'Qiick\Audit\Laravel\AuditBootChecks'
  'Qiick\Audit\Support\RetentionPurger'
  'Qiick\Audit\Actions\CleanActivityLogAction'
  'Qiick\Audit\Console\Commands\PurgeActivityLogCommand'
  'Qiick\Audit\Console\Commands\CheckAuditCommand'
  'Qiick\Audit\Anonymisation\ActivityAnonymiser'
  'Qiick\Audit\Contracts\ErasureSubject'
  'Qiick\Audit\Contracts\MorphRef'
  'Qiick\Audit\Audit'
  'Qiick\Audit\AuditContext'
  'Qiick\Audit\Contracts\AuditActor'
  'Qiick\Audit\Resolvers\RequestAttributeActorResolver'
  'Qiick\Audit\Support\ActivityAttributor'
  'Qiick\Audit\Concerns\LogsActivity'
  'Qiick\Audit\Relations\StringKeyedMorphMany'
  'Qiick\Audit\Support\ActivityLogger'
  'Qiick\Audit\Support\SecretAttributeFloor'
  'Qiick\Audit\Actions\LogActivityAction'
  'Qiick\Audit\Laravel\AuditServiceProvider'
  'Qiick\Audit\Models\Activity'
  'Qiick\Audit\Erasure\AuditEraser'
  'Qiick\Audit\Erasure\ErasureReport'
  'Qiick\Audit\Support\AuditCommitService'
  'Qiick\Audit\Anonymisation\LowercaseAuditColumnExpression'
)

MIN="${MUTATE_MIN:-95}"
SHARD="${MUTATE_SHARD:-1}"
SHARDS="${MUTATE_SHARDS:-1}"

if [[ "${#CLASSES[@]}" -eq 0 ]]; then
  echo "No mutation target classes yet (scaffold). Skipping."
  exit 0
fi

status=0
declare -a TABLE_ROWS=()

for index in "${!CLASSES[@]}"; do
  if (( index % SHARDS != SHARD - 1 )); then
    continue
  fi

  class="${CLASSES[$index]}"
  echo "::group::mutate ${class}"
  echo "==> mutate ${class} (min=${MIN}, shard ${SHARD}/${SHARDS})"

  output="$(mktemp)"
  set +e
  vendor/bin/pest --mutate --min="${MIN}" --class="${class}" | tee "${output}"
  class_status=${PIPESTATUS[0]}
  set -e

  # Strip ANSI before parsing: pest colours the Score line.
  plain="$(sed $'s/\x1b\\[[0-9;]*m//g' "${output}")"
  score="$(printf '%s\n' "${plain}" | grep -oE 'Score:[[:space:]]+[0-9.]+%' | tail -1 | grep -oE '[0-9.]+' | head -1 || true)"

  # A listed class with no mutants is a mis-path or an empty target.
  if printf '%s\n' "${plain}" | grep -q 'No mutations created'; then
    echo "::error::No mutations created for ${class} (mis-pathed class?)"
    class_status=1
  fi

  TABLE_ROWS+=("| \`${class}\` | ${score:-?}% |")
  if [[ "${class_status}" -ne 0 ]]; then
    echo "::error::Mutation score below ${MIN}% for ${class}"
    status=1
  fi
  rm -f "${output}"
  echo "::endgroup::"
done

{
  echo "## Per-class mutation scores (shard ${SHARD}/${SHARDS})"
  echo "| Class | Score |"
  echo "| --- | --- |"
  for row in "${TABLE_ROWS[@]}"; do
    echo "${row}"
  done
} | tee -a "${GITHUB_STEP_SUMMARY:-/dev/null}"

exit "${status}"
