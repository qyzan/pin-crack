# Contributing

Thanks for taking the time. This is a small, deliberately dependency-free
tool, so a few constraints are non-negotiable.

## Ground rules

1. **No runtime dependencies.** The tool must work from a bare `git clone`
   with nothing but PHP 8.1+. Composer is for the test suite only. If you need
   a library at runtime, the feature probably belongs somewhere else.
2. **Windows counts.** Most of the ugly parts of this codebase exist because
   `stream_select()` does not work on `proc_open` pipes on Windows and
   `rename()` does not overwrite there. Do not "simplify" those away without
   testing on Windows — CI runs there for a reason.
3. **Honest output.** The ordering of the built-in candidate lists, the
   reported position of a hit, the `[BURST]` caveat on benchmarks and the
   warning printed for `--include` are all there so that a result can be put
   in a report and defended. Changes that make the tool look faster or luckier
   than it is will not be merged.
4. **No new ways to point it at a target.** This tool reads hashes and files.
   It does not fetch hashes from a URL, log in anywhere, or talk to an API.

## Setup

```bash
composer install
composer check        # phpstan (level 6) + phpunit
php vendor/bin/phpunit --testsuite unit
```

`composer check` must be green before you open a pull request. CI runs the
same thing on PHP 8.1–8.4 on Linux, Windows and macOS, plus a run with
Composer's autoloader removed, to prove the bare-clone path still works.

## Style

- `declare(strict_types=1);` in every file, PSR-12, four spaces.
- Classes are `final` unless there is a reason not to be.
- Comments explain **why**, not what. The existing ones document bugs that
  were actually hit — that history is valuable, please keep it when you touch
  the surrounding code.
- Keep CLI text in English; translated documentation is welcome as
  `README.<lang>.md`.

## Tests

- Anything that touches candidate generation, option parsing, hash
  identification or checkpoint logic needs a unit test.
- Anything that changes the CLI contract — flags, exit codes, output shape —
  needs an integration test in `tests/Integration`.
- Generate hashes inside the test, at bcrypt cost 4, so the suite stays fast.
  **Never commit real hashes.**

## Candidate list changes

Additions to the built-in wordlists should come with a reason: a published
frequency analysis, a documented PIN-selection pattern, a locale-specific
shape. "I have seen this one" is not enough, because every entry you add in
front of another changes what the reported position means.

## Pull requests

One topic per pull request. Describe what changed and how you verified it,
including the OS and PHP version you ran it on. Update `CHANGELOG.md` under
`Unreleased`.
