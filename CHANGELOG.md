# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [4.0.0] - 2026-11-18

First public release. The tool started life as a single-file proof of concept
for one engagement finding; this release is that logic rebuilt as a
maintainable project.

### Added

- Mask support (`--mask`) with hashcat-style tokens `?d ?l ?u ?s ?a ?h ?H`
  and literals, so the tool is no longer limited to pure digits.
- Hash families beyond bcrypt: argon2i/d/id are detected automatically, as are
  bare md5, sha1, sha256, sha384 and sha512 digests. Salted digests are
  supported through `--algo`, `--salt`, `--salt-format` and `--iterations`.
- Arbitrary wordlists of mixed-length candidates: when records are not a
  uniform width, a side-car offset index is written so workers can still seek
  directly to their first record.
- `--json` machine-readable summary, and `--out` to append recovered
  `label:secret` pairs to a file.
- `--max-candidates` guard, which refuses an oversized source before writing
  anything to disk.
- `--version`, and an exit code (`3`) distinguishing "stopped early, progress
  saved" from "finished, nothing found".
- Test suite (99 tests) and GitHub Actions CI across PHP 8.1–8.4 on Linux,
  Windows and macOS, including a run with Composer's autoloader removed.
- PHPStan level 6 in CI.

### Changed

- Rebuilt as PSR-4 namespaced classes under `src/` with `bin/pin-crack` as the
  entry point. The tool still runs from a bare clone with no Composer
  install, via a fallback autoloader.
- The target hash is now passed to workers in a job file inside a `0700`
  temporary directory instead of on the command line, so it no longer appears
  in the machine's process list.
- Worker status reporting interval reduced from 1 s to 0.25 s, and on a hit
  the parent now waits for the other workers to publish their final counts.
  Previously a sweep that finished in under a second reported only the
  finding worker's count, making `tried` look far too low.
- CLI output and code comments are in English; the Indonesian overview lives
  in `README.id.md`.
- All references to the specific engagement the original PoC was written for
  have been removed.

### Fixed

- `Json::writeAtomic()` now unlinks the destination before `rename()` on
  Windows, where `rename()` does not overwrite an existing file. The status
  protocol could previously stall there.
- The bcrypt detection pattern no longer mis-parses its own delimiter, which
  produced a `preg_match(): Unknown modifier` warning and caused every valid
  bcrypt hash to be reported as unrecognised.

### Inherited from the 3.x proof of concept

These were fixed before this release and are listed because the reasons are
documented in the code and worth keeping:

- Coordination uses status files rather than `proc_open` pipes.
  `stream_select()` and non-blocking mode do not work on `proc_open` pipes on
  Windows, which lost inter-process messages and hung the parent.
- The parent no longer breaks out of the status-collection loop as soon as a
  hit is seen, which previously dropped the candidate counts of every shard
  after the finding one.
- The throughput guard is time-based rather than iteration-based. Counting
  loop iterations meant "40 iterations" was under a second, so the guard
  slept for two minutes on ordinary rate jitter.
- Progress lines are padded to a fixed width before being redrawn, so
  leftovers from a longer previous render cannot corrupt the display.
- Rate and ETA are withheld for the first ten seconds instead of dividing a
  start-up burst by a very short interval.

[Unreleased]: https://github.com/qyzan/pin-crack/compare/v4.0.0...HEAD
[4.0.0]: https://github.com/qyzan/pin-crack/releases/tag/v4.0.0
