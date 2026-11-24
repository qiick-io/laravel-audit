# Contributing to laravel-audit

Thanks for helping. A few rules keep the codebase consistent:

1. Open or pick an issue first. One cohesive area per pull request.
2. Branch from `develop` as `<type>/<kebab-slug>` (types: feat, fix, refactor,
   chore, docs, test, perf, ci, build, security).
3. Use Conventional Commits; PR titles follow the same format. Body lines ≤ 100
   characters.
4. Open the PR against `develop`. PRs are squash-merged. Never push to
   `develop` or `main` directly; never tag from a contributor branch; never
   touch Packagist.
5. Before pushing run `composer test` (Pest), `composer lint` (Pint) and
   `composer analyse` (PHPStan level max). Every class with behaviour is a
   mutation target (`scripts/mutate-per-class.sh`, per-class MSI ≥ 95%, no
   `@pest-mutate-ignore`). CI runs the mutation shards; run single classes
   locally.
6. Red-first: every behaviour and every security item of the design (A1–A14)
   becomes a failing test first, named after the item. Tests are
   deterministic (fakes for every contract; no network or `.env`).
7. Code style: strict types, `final` by default, English docblocks that explain
   *why*, no global helpers, no `Illuminate\Foundation\` or `App\` in `src/`.
   Only `@api` types are SemVer BC.
8. Security and privacy checks are never weakened. Changes to the secret
   floor, the reserved property keys, attribution, the organisation stamp, the
   anonymiser, the purge or the boot checks need a test that proves the bad
   path is refused.
9. Do not commit `composer.lock`, secrets, or `graphify-out/`.
10. Releases and Packagist: maintainer only (see SECURITY.md and the qiick
    release standard).
