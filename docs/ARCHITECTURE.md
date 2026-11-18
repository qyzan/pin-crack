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

Three classes carry the work:

- `Candidates\CandidateBuilder` → writes a `CandidateSet` to disk.
- `Cracking\ParallelCracker` → splits that set, spawns workers, polls them.
- `Cracking\WorkerRunner` → one worker: one contiguous range, one status file.

`Cli\Application` wires them together and owns everything the operator sees.

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
matches `sha256(hash | source label | total | workers)`.

That is deliberately strict. Silently resuming a different job would make the
tool report coverage that never happened, and coverage is the one number the
whole exercise exists to produce.

Shards are contiguous and resume at their own `pos`, which is why the worker
count is part of the signature: a different count means a different layout.

## Safeguards for multi-hour runs

| Mechanism | Where | Why |
|---|---|---|
| OS priority (`below`, `idle`) | `Support\System::lowerPriority()` | the machine stays usable; on an idle box throughput is practically unaffected |
| throughput guard | `Cracking\ThroughputGuard` | sustained collapse means thermal or power limits; pausing recovers more total work than grinding on |
| time limit | `--max-hours` | stop cleanly, with progress saved, instead of being killed |
| keyspace limit | `--max-candidates` | refuse an absurd source before writing hundreds of MB |

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

## Deliberate non-goals

- **No GPU.** This is PHP. One core does ~25–30 bcrypt cost-12 verifications
  per second. If you need speed, use hashcat.
- **No network.** The tool reads hashes and files. It does not fetch, log in,
  or report anywhere.
- **No runtime dependencies.** It has to run from a bare clone on a client
  laptop with nothing but PHP installed.
- **No rule engine.** Candidate generation covers wordlists, patterns and
  masks. Rule-based mangling is what dedicated crackers are for.
