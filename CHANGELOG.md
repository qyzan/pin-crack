# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [5.0.0] - 2026-11-18

An audit of 4.0.0 — measuring rather than reading — found three significant
bugs. Two of them made the tool unsafe to leave running unattended, which is
the thing it was built for. All three are fixed, with the measurements that
found them recorded in the code and docs.

Several defaults and the exit-code contract change, hence the major version.

### Fixed

- **The throughput guard never actually paused anything.** The parent printed
  "pausing 120 s to cool down" and called `sleep()` on itself while every
  worker kept hashing at 100%. It also froze the display, skipped checkpoints
  for the whole pause, and could overshoot `--max-hours` by a full cooldown.
  The parent now creates a `PAUSE` file that the workers idle on, and keeps
  checkpointing and honouring the time limit while they do. Verified
  end-to-end: a worker's candidate count stays frozen while paused and resumes
  afterwards.
- **Killing the parent orphaned every worker.** Workers only stopped on
  finishing their shard or seeing `STOP`, which a dead parent never writes.
  Measured: 4 of 4 workers still burning CPU four seconds after the parent was
  killed, and one shard of a 6-digit bcrypt sweep can run for 40 hours. The
  parent now touches a `HEARTBEAT` file and a worker exits by itself when it
  goes stale.
- **A worker-count change silently destroyed a checkpoint.** The signature
  included the worker count, so `--workers 8 --resume` after a `--workers 12`
  run found a "different job", started from zero, and overwrote the
  checkpoint on the way — no message, hours of progress gone. `--max` made it
  worse by *measuring* the worker count, which a warmer machine can measure
  differently. Shards are now independent of the pool size, and a checkpoint
  that genuinely belongs to another job is refused and left untouched.
- Control files are read with the stat cache cleared first. PHP caches
  successful `stat()` calls, so a worker could have stayed parked forever
  after `PAUSE` was removed. (Found by PHPStan, not by a test.)
- `--workers` is honoured on resume. The old code spawned one worker per
  pending shard regardless of the value given.
- `Filesystem::freeSpace()` was dead code; it is now a preflight check, so
  `--digits 10 --full --max-candidates 0` is refused instead of writing until
  the volume fills.
- The single-process path ignored `--guard` entirely.
- The header read "targets : 1 hashes".

### Changed

- **Default worker count is now logical processors minus two**, capped at four
  for raw digests. Measured on a 12-thread hybrid CPU: bcrypt cost 12 ran at
  15.81 H/s with ten workers against 14.02 H/s with twelve, and a full md5
  keyspace took 1.35 s with up to four workers against 1.84 s with twelve. The
  old default of one worker per logical processor was measurably the worse
  choice in both cases.
- **`--max-hours` is a budget for the whole invocation**, not per hash. Ten
  hashes with `--max-hours 8` used to be allowed eighty hours.
- Workers run as a pool over a fixed shard plan rather than one worker per
  shard.
- `--max` auto-tune also considers `cpuCount - 2`, which its old candidate set
  of {half, three quarters, all} could not express.
- Checkpoint format version 5. Checkpoints from 4.0.0 are not resumable.
- Input problems (a bad mask, an oversized source, an empty candidate list)
  exit 2; environmental failures exit 4. They were both 2.

### Added

- Exit code `4` for runtime errors — out of disk, an unreadable file, no
  worker could start — so a script can tell "you typed it wrong" from "the
  machine could not do it".
- `Cracking\ShardPlanner`, which owns the shard plan and its invariants.
- 21 more tests (120 total), including the end-to-end guard test whose absence
  hid the original bug, and worker-level tests for the heartbeat and stop
  paths.

### Documentation

- The measured worker-scaling curve, the 6.8% shard-imbalance figure, the 3.9x
  thermal drift observed within one session, and the reason this stays in PHP
  (PHP accounts for 0.0001% of per-candidate time on the bcrypt path, so a
  rewrite in a faster language would gain roughly nothing).

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

[Unreleased]: https://github.com/qyzan/pin-crack/compare/v5.0.0...HEAD
[5.0.0]: https://github.com/qyzan/pin-crack/compare/v4.0.0...v5.0.0
[4.0.0]: https://github.com/qyzan/pin-crack/releases/tag/v4.0.0
