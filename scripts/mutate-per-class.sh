#!/usr/bin/env bash
# Enforce pest --mutate --min=95 per class (no --covered-only, never || true,
# never @pest-mutate-ignore). One invocation per class so a weak class cannot
# hide behind a high overall score.
#
# Whole source: every file in src/ runs. A listed class must reach the
# minimum; any other file must create no mutant at all (interfaces, value-only
# events and exceptions, one-line delegations), so a new class with behaviour
# fails until it is listed here.
#
# MUTATE_SCOPE=changed (pull requests, from app-ui's package-matrix) runs only
# the src/ paths in MUTATE_CLASSES, a JSON array; `all` (the default) runs
# every file. CI splits the files into parallel shards: MUTATE_SHARD=i
# MUTATE_SHARDS=n runs the files at positions i, i+n, i+2n, … (1-based).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

# Every class with behaviour is a target. Today pest creates no mutant for the
# other files: the contracts, the events, OrganisationScopeUnsupportedException,
# NullOrganisationResolver (returns null), Concerns\CausesActivity and
# Concerns\HasActivity (one-line delegations to listed classes).
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

case "${MUTATE_SCOPE:-all}" in
  all)
    files="$(find src -type f -name '*.php' | LC_ALL=C sort)"
    ;;
  changed)
    files="$(node -e '
      const files = JSON.parse(process.env.MUTATE_CLASSES ?? "[]");
      if (!Array.isArray(files) || files.some(file => typeof file !== "string" || !/^src\/[\w/]+\.php$/.test(file))) {
        throw new Error("MUTATE_CLASSES must be an array of package source paths");
      }
      console.log(files.join("\n"));
    ')"
    ;;
  *)
    echo "::error::MUTATE_SCOPE must be all or changed."
    exit 1
    ;;
esac

status=0
position=0
declare -a TABLE_ROWS=()

while IFS= read -r file; do
  # A pull request's list includes deleted paths: only surviving files run.
  if [[ -z "${file}" || ! -f "${file}" ]]; then
    continue
  fi

  position=$((position + 1))
  if (( (position - 1) % SHARDS != SHARD - 1 )); then
    continue
  fi

  relative="${file#src/}"
  relative="${relative%.php}"
  class="Qiick\\Audit\\${relative//\//\\}"
  is_target=false
  for listed in "${CLASSES[@]}"; do
    if [[ "${listed}" == "${class}" ]]; then
      is_target=true
    fi
  done

  echo "::group::mutate ${class}"
  echo "==> mutate ${class} (min=${MIN}, target=${is_target}, shard ${SHARD}/${SHARDS})"

  output="$(mktemp)"
  set +e
  vendor/bin/pest --mutate --min="${MIN}" --path="${file}" --ignore-min-score-on-zero-mutations | tee "${output}"
  class_status=${PIPESTATUS[0]}
  set -e

  # Strip ANSI before parsing: pest colours the Score line.
  plain="$(sed $'s/\x1b\\[[0-9;]*m//g' "${output}")"
  score="$(printf '%s\n' "${plain}" | grep -oE 'Score:[[:space:]]+[0-9.]+%' | tail -1 | grep -oE '[0-9.]+' | head -1 || true)"
  has_no_mutants=false
  if printf '%s\n' "${plain}" | grep -q 'No mutations created'; then
    has_no_mutants=true
  fi

  if [[ "${is_target}" == true && "${has_no_mutants}" == true ]]; then
    # A listed class with no mutants is a mis-path or an empty target.
    echo "::error::No mutations created for ${class} (mis-pathed class?)"
    class_status=1
  elif [[ "${is_target}" == false && "${has_no_mutants}" == false ]]; then
    echo "::error::${class} has mutants but is not a target: add it to CLASSES."
    class_status=1
  fi

  if [[ "${has_no_mutants}" == true ]]; then
    TABLE_ROWS+=("| \`${class}\` | no mutants |")
  else
    TABLE_ROWS+=("| \`${class}\` | ${score:-?}% |")
  fi

  if [[ "${class_status}" -ne 0 ]]; then
    echo "::error::Mutation gate failed for ${class}"
    status=1
  fi
  rm -f "${output}"
  echo "::endgroup::"
done <<< "${files}"

{
  echo "## Per-class mutation scores (shard ${SHARD}/${SHARDS})"
  echo "| Class | Score |"
  echo "| --- | --- |"
  for row in "${TABLE_ROWS[@]}"; do
    echo "${row}"
  done
} | tee -a "${GITHUB_STEP_SUMMARY:-/dev/null}"

exit "${status}"
