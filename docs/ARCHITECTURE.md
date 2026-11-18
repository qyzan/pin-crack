# Architecture

This document explains the design and, more usefully, why it is shaped the way
it is. Most of the odd-looking decisions are scars.

## Layout

```
bin/pin-crack            entry point; also re-invoked as a worker
src/
  Cli/                   option parsing, target loading, orchestration, help
  Candidates/            candidate generation and materialisation
  Cracking/              workers, the parent loop, checkpoints, benchmarking
  Verify/                hash identification and per-candidate verification
  Support/               formatting, console, JSON, filesystem, OS priority
  autoload.php           fallback PSR-4 autoloader for Composer-less use
tests/
  Unit/                  pure logic
  Integration/           the real binary, including the multi-process path
```

Four classes carry the work:

- `Candidates\CandidateBuilder` → writes a `CandidateSet` to disk.
- `Cracking\ShardPlanner` → splits that set into contiguous shards.
- `Cracking\ParallelCracker` → runs a pool of workers over the shards.
- `Cracking\WorkerRunner` → one worker: one shard, one status file.

`Cli\Application` wires them together and owns everything the operator sees.

## Shards are not workers

The shard plan is a property of the candidate list; the worker count only caps
how many run at once. Workers form a **pool** that claims shards from a queue.

This separation exists because the alternative was a bug. The checkpoint
signature used to include the worker count, so `--workers 8 --resume` after a
`--workers 12` run found a "different job", started from zero, and overwrote
the old checkpoint on its way — silently, with no message, destroying hours of
progress. The documented `--full --max --resume` workflow made it worse,
because `--max` *measures* the worker count and a warmer machine can measure
differently.

A pool also makes `--workers` meaningful on resume, which it was not: the old
code spawned one worker per pending shard regardless of what you asked for.

Measured aside: equal-sized static shards are good enough. Over the same
wall-clock window on a hybrid CPU (2 P-cores + 8 E-cores), twelve shards
completed 5,522–5,907 candidates each — a 6.8% spread, because the OS migrates
threads between core types. Work stealing would buy back only that ~7% tail
while costing a process spawn per chunk, so one shard per worker stays the
default.

## Candidates live in a file, not in memory

A PHP array of one million strings exceeds the default 128 MB `memory_limit`,
and the whole design depends on several processes reading disjoint slices of
the same list. So candidates are generated once, streamed to a file, and
addressed by record number.

Two seek strategies:

| Case | Strategy | Cost |
|---|---|---|
| all candidates the same length | `offset = n × recordLength` | free |
| mixed lengths (user wordlist) | side-car file of 64-bit offsets | 8 bytes per record |

The index is only built when a candidate of a different length actually shows
up. Everything written before that point had a uniform length, so its offsets
are arithmetic and get backfilled without re-reading the data file
(`CandidateWriter::startIndex()`).

Front-loaded candidates from `--include` are written first and excluded from
the generated list, so the reported position of a hit stays meaningful.

## Coordination uses files, not pipes

**This is the single most important decision in the codebase.**

Version 2.x used `proc_open` pipes with `stream_select()` and
`stream_set_blocking(false)`. Neither works on `proc_open` pipes on Windows —
a long-standing PHP limitation. The symptoms were:

- inter-process messages going missing,
- a chunk finishing with no completion report,
- the parent waiting forever,
- all of it more frequent with cheap hashes, because the race got tighter.

So: each worker owns a contiguous range and writes its progress to a small
JSON file, write-then-rename so it is never read half-written. The parent only
reads files. Worker stdout and stderr go to a log file, which means no pipe can
fill up and block, and terminating a worker is always safe.

On Windows `rename()` does not overwrite, so `Support\Json::writeAtomic()`
unlinks the destination first on that platform.

### The three control files

Status flows from worker to parent. Three files flow the other way, and a
worker checks all of them at its reporting cadence:

| File | Meaning for a worker |
|---|---|
| `STOP` | finish now; the position just reported is final |
| `PAUSE` | stop verifying candidates and idle until it disappears |
| `HEARTBEAT` | the parent is alive; if its mtime goes stale, exit |

Every one of those checks clears the stat cache for that path first. PHP caches
successful `stat()` results for the life of the process, so a plain `is_file()`
loop keeps seeing a file the parent already deleted — for `PAUSE` that is the
dangerous direction, and a worker would stay parked forever after the cooldown
ended.

### PAUSE: the guard has to reach the workers

The throughput guard used to decide correctly and then do nothing. The parent
printed "pausing 120 s to cool down" and called `sleep()` on itself while all
N workers kept hashing at 100%. It also froze the display, skipped checkpoints
for the whole pause, and could overshoot `--max-hours` by a full cooldown.

Now the parent creates `PAUSE`, and keeps working during the cooldown: it goes
on touching the heartbeat (or paused workers would conclude it had died), keeps
the checkpoint current, and still honours the time limit.

Its unit test passed the whole time, because it tested the decision logic in
isolation. There is now an integration test that drives a real worker through
`--worker --job` and asserts its candidate count does not move while `PAUSE`
exists.

### HEARTBEAT: no orphans

A worker only ever stopped when it finished its shard or saw `STOP` — which a
dead parent never writes. Killing the parent therefore left every worker
hashing: measured, 4 of 4 still burning CPU four seconds later, and a single
shard of a 6-digit bcrypt sweep can run for 40 hours.

The parent touches `HEARTBEAT` before spawning anything and on every poll. A
worker that sees it older than 30 seconds, or gone entirely, exits by itself.
Supervision is opt-in, so the benchmark harness can run workers unsupervised.

### What the parent reads

Each status file holds `pos`, `tried`, `done`, `found`, `index`, `stopped`.
Every poll, the parent reads *all* of them and sums `tried`. It records a hit
but **does not break out of that loop** — an earlier version did, so shards
after the finding one were left out of the sum. On one real sweep the secret
sat in shard 9 of 12 and the report lost 154,820 candidates, printing
23.63 H/s where the true figure was 31.29 H/s.

On a hit the parent then touches the stop file and waits up to a second for
the other workers to publish their final counts, so `tried` reflects the work
every shard actually did. Workers report every 0.25 s; with the previous
one-second interval, a run that ended in 400 ms reported only the finding
worker's count.

## The hash does not go on the command line

Workers are re-invocations of `bin/pin-crack --worker --job <file>`. The job
file — inside a `0700` temporary directory — carries the candidate path, the
range, the status and stop paths, the target hash and the verifier options.

Putting the hash in an argument would publish it to every process listing on
the machine, which is not acceptable for client material.

## Checkpoints are refused unless they match

A checkpoint stores the shard layout, cumulative `tried`, cumulative
`elapsed`, and the secret once found. It is accepted only when its signature
matches `sha256(hash | source label | total)`.

That is deliberately strict. Silently resuming a different job would make the
tool report coverage that never happened, and coverage is the one number the
whole exercise exists to produce.

What is *not* in the signature is the worker count — see "Shards are not
workers" above for why that was a bug.

`Checkpoint::status()` distinguishes three cases, and the CLI treats them
differently: missing, usable, and mismatched. A mismatched file is somebody's
unfinished sweep, so with `--resume` the run is refused and the file is left
exactly as it was. An unreadable or truncated file counts as mismatched rather
than absent, for the same reason: the operator gets told instead of having it
replaced.

## Safeguards for multi-hour runs

| Mechanism | Where | Why |
|---|---|---|
| OS priority (`below`, `idle`) | `Support\System::lowerPriority()` | the machine stays usable; on an idle box throughput is practically unaffected |
| throughput guard | `Cracking\ThroughputGuard` + `PAUSE` | sustained collapse means thermal or power limits; pausing recovers more total work than grinding on |
| heartbeat | `HEARTBEAT` | a dead parent must not leave workers hashing for hours |
| time limit | `--max-hours` | stop cleanly, with progress saved, instead of being killed. A budget for the whole run, not per hash |
| keyspace limit | `--max-candidates` | refuse an absurd source before writing a byte |
| disk preflight | `Filesystem::freeSpace()` | `--digits 10 --full --max-candidates 0` would otherwise write until the volume fills |

Thermal reality, measured on one laptop across a single session — same code,
same machine, bcrypt cost 12 single-threaded:

```
5.72 H/s  (cold)
2.97 H/s
1.80 H/s
1.48 H/s  (hot: 675 ms per hash)
```

A 3.9x drift. That is why `--bench` numbers carry caveats, and why the guard is
the feature this tool most needs to get right.

The guard is **time-based**, not iteration-based. The old version counted loop
iterations; with the parent spinning every 20 ms, "40 iterations" was under a
second, so it slept two minutes on ordinary jitter. It now requires a
sustained low rate, only after a warm-up window, and only while there is real
work left — pausing two minutes before the end helps nobody.

## Honest progress reporting

Rate and ETA are withheld until ten seconds have passed. Before that, a
start-up burst divided by a very short interval produces figures like
"3557 H/s, 4.7 min left" for a job that is really hours long. A missing ETA is
better than a nonsense one.

Multi-line progress is redrawn with every line padded to a fixed width.
Without that, only the first line gets cleared and the tail of a longer
previous render stays glued to lines 2–4 — which showed up as impossible clock
values like `ETA 13 Nov 23:07:126`, where `126` was leftover text.

Benchmarks label the parallel figure `[BURST]`, because a ~20 s sweep still
catches turbo boost. On one test machine a 400 s sweep gave 8.67 H/s where the
burst figure was 22.58 H/s.

## Verification

`Verify\VerifierFactory` picks an implementation from the hash string, or from
`--algo` when no hash can describe itself:

- `CryptVerifier` → `password_verify()`, which parses cost and salt out of the
  hash itself. bcrypt and argon2.
- `DigestVerifier` → `hash()` plus `hash_equals()`, with an optional salt
  template (`{salt}{secret}`) and iteration count.

A verifier is cheap to build and is rebuilt inside each worker from the job
file, so nothing has to be serialised across processes.

## Why this stays in PHP

The obvious objection to a cracker written in PHP is throughput. Measured, on
the bcrypt path:

```
per-candidate cost, bcrypt cost 12
  tool bookkeeping (fgets + rtrim) :      0.70 us
  one password_verify              : 675429.82 us
  PHP's share                      :   0.0001%
```

`password_verify()` is a call into C. The interpreter sits and waits. Rewriting
this tool in Rust, C or Go would make bcrypt and argon2 roughly **0% faster**,
which is the whole reason the language question has a boring answer.

Where another language would genuinely win is the cheap-hash path — PHP does
md5 at ~731k H/s single-threaded where native SIMD code is two to three orders
of magnitude faster — and the process model: real threads would replace twelve
processes, the status files, and all three control files with a few atomics,
and would make signal handling possible (PHP has no `pcntl` on Windows, which
is why orphan prevention is a heartbeat file rather than a signal handler).

Neither is worth a rewrite for this tool's purpose. If you want speed, hashcat
is 400x faster than any CPU implementation and already exists.

## Deliberate non-goals

- **No GPU.** This is PHP. One core does ~25–30 bcrypt cost-12 verifications
  per second. If you need speed, use hashcat.
- **No network.** The tool reads hashes and files. It does not fetch, log in,
  or report anywhere.
- **No runtime dependencies.** It has to run from a bare clone on a client
  laptop with nothing but PHP installed.
- **No rule engine.** Candidate generation covers wordlists, patterns and
  masks. Rule-based mangling is what dedicated crackers are for.
