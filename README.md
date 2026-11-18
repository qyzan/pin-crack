<div align="center">

# pin-crack

**Offline recovery of short secrets from leaked password hashes.**
PINs, numeric passcodes, 4–8 character passwords. Pure PHP, zero dependencies.

[![CI](https://github.com/qyzan/pin-crack/actions/workflows/ci.yml/badge.svg)](https://github.com/qyzan/pin-crack/actions/workflows/ci.yml)
[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Dependencies: none](https://img.shields.io/badge/dependencies-none-success)](composer.json)
[![Tests: 99](https://img.shields.io/badge/tests-99-success)](tests)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

*Linux · macOS · Windows &nbsp;•&nbsp; bcrypt · argon2 · md5/sha1/sha2 &nbsp;•&nbsp; resumable multi-hour sweeps*

</div>

> [!WARNING]
> **For authorized security testing only.** Run this against hashes you own or
> are contracted to test. See [SECURITY.md](SECURITY.md).

---

## The argument in one table

This tool is not about weak hashing. The hashing is usually *correct*. It is
about **keyspace** — and that is a number no hash function can change.

Time to exhaust the entire keyspace, measured on one ordinary laptop
(Intel Core i7-1355U, 12 logical processors, 12 workers):

| Secret shape | Keyspace | Unsalted md5 | bcrypt cost 12 |
|---|--:|--:|--:|
| 4-digit PIN | 10,000 | 0.02 s | **10 minutes** |
| 6-digit PIN | 1,000,000 | **1.9 s** ¹ | **17 hours** ² |
| 8-digit PIN | 100,000,000 | 3.1 minutes | 71 days |

<sub>¹ measured end-to-end: full 1,000,000-candidate sweep, secret at position 997,532 of 1,000,000 — near worst case — in 1.86 s at 534,821 H/s.
² extrapolated from a measured 16.31 H/s burst rate; a sustained multi-hour sweep is slower, see [the honesty section](#numbers-you-can-defend). Expected time to recovery is half of these figures.</sub>

Read the bcrypt column again. Correct, salted bcrypt at cost 12 turns a
1.9-second problem into a **weekend** problem. It does not turn it into an
impossible one, and:

- the attack runs **offline** — rate limiting, lockout, anomaly detection and
  MFA all live on the server, and none of them is in the path;
- raising the cost buys a constant factor against an attacker who only needs a
  million guesses;
- most PINs are not uniformly distributed, so the real figure is usually far
  lower than the table (see the demo below: **46 guesses**).

That is the output of this tool: the wall-clock time between a leaked hash and
the secret. It is the number that turns *"a hash leaked, but it's bcrypt"*
into a decision.

---

## See it work

A real run against bcrypt cost 12 — the full output, nothing trimmed:

```console
$ php bin/pin-crack --hash-file hashes.txt --workers 12

pin-crack 4.0.0 - offline secret recovery
----------------------------------------------------------------
  candidates : 3,469  (built-in common wordlist)
  list file  : 23.7 KiB, fixed records
  workers    : 12 of 12 logical processors
  priority   : normal
  checkpoint : off
  guard      : pause 120 s when the rate stays below 50% of the peak
  targets    : 1 hash
----------------------------------------------------------------

[alice] bcrypt $2y$ cost 12
  >> RECOVERED : 112358
     position  : candidate 46 of 3,469
     tried     : 554 candidates
     time      : 29 s
     throughput: 19.08 H/s

----------------------------------------------------------------
done: 1 of 1 hashes recovered
```

**`candidate 46 of 3,469`** is the whole finding. The built-in lists are
ordered most-likely-first, so that one line separates *"a trivial dictionary
attack works"* from *"the full keyspace was needed"* — and it is the sentence
that goes in the ticket.

During a long sweep you get a live display instead:

```console
  [##########..................]  37.2%
  372,118 / 1,000,000 candidates   16.4 H/s (avg 15.9)
  elapsed 6.5 h   left ~10.6 h   ETA 19 Nov 04:12
  12 workers @ below priority   cooldowns: 2
```

---

## Install

PHP 8.1 or newer. That is the entire list.

```bash
git clone https://github.com/qyzan/pin-crack.git
cd pin-crack
php bin/pin-crack --help
```

No `composer install`, no extensions to build, no toolchain — which is the
point when you are on a borrowed machine in a client's office. Composer is
only needed to run the test suite:

```bash
composer install && composer check    # phpstan + 99 tests
```

<details>
<summary>Install as a project dependency instead</summary>

```bash
composer require --dev qyzan/pin-crack
vendor/bin/pin-crack --help
```

</details>

---

## Quick start

```bash
# Common-PIN wordlist, hash type detected automatically, one worker per core
php bin/pin-crack --hash '$2y$12$...'

# The whole 6-digit keyspace on an idle machine, overnight, resumable
php bin/pin-crack --hash '$2y$12$...' --full --max

# Interrupted? Pick up exactly where it stopped
php bin/pin-crack --hash '$2y$12$...' --full --max --resume

# A table full of hashes, JSON for your report pipeline
php bin/pin-crack --hash-file hashes.txt --full --json --out recovered.txt

# How long would the full keyspace take on *this* machine?
php bin/pin-crack --hash '$2y$12$...' --bench
```

Full option reference: **[docs/USAGE.md](docs/USAGE.md)**

---

## What it handles

<table>
<tr><th align="left">Hash family</th><th align="left">Detection</th><th align="left">Notes</th></tr>
<tr><td><code>$2$ $2a$ $2b$ $2x$ $2y$</code></td><td>automatic</td><td>bcrypt; cost read from the hash</td></tr>
<tr><td><code>$argon2i$ $argon2d$ $argon2id$</code></td><td>automatic</td><td>m, t, p read from the hash</td></tr>
<tr><td>md5, sha1, sha256, sha384, sha512</td><td>automatic, by length</td><td>unsalted</td></tr>
<tr><td>the same digests, salted</td><td><code>--algo --salt --salt-format</code></td><td>e.g. <code>'{salt}{secret}'</code>, plus <code>--iterations</code></td></tr>
<tr><td>anything else</td><td><code>--algo &lt;name&gt;</code></td><td>any algorithm <code>hash_algos()</code> lists</td></tr>
</table>

An unidentifiable hash is **skipped with a message**, never silently counted as
"not recoverable" — a bad line in a hash file must not become a clean bill of
health.

## Candidate sources

| Flag | Covers |
|---|---|
| *(default)* | curated frequent PINs, repeat/alternating patterns, sequences, birth-year shapes — **ordered most likely first** |
| `--dates` | adds `ddmmyy` / `mmddyy` / `yymmdd` (≈87,000 candidates) |
| `--full` | the entire numeric keyspace, 10ᴺ |
| `--mask '19?d?d'` | hashcat-style masks — `?d ?l ?u ?s ?a ?h ?H`, `??` for a literal `?` |
| `--wordlist rockyou.txt` | any file, streamed, mixed lengths fine — **replaces** the built-ins |
| `--include 1234,0000` | force candidates to the front *(prints a warning — see below)* |
| `--save-wordlist wl.txt` | write the list and exit, without touching a hash |

Masks are not limited to digits: `--mask '?u?l?l?d?d?d'` covers the `Abc123`
shape, `--mask '19?d?d'` covers 1900–1999.

## Built for sweeps that run all night

A full 6-digit sweep against cost-12 bcrypt is an overnight job. The tool
assumes that and is built to survive it:

| Flag | What it does |
|---|---|
| `--max` | idle-machine profile: **measures** the best worker count, lowers priority, enables checkpointing and the guard |
| `--checkpoint F` / `--resume` | progress survives a reboot, a closed lid, a killed terminal |
| `--max-hours N` | stop cleanly after N hours with progress saved — exit code `3`, not a lie |
| `--priority below\|idle\|normal` | OS priority, so the machine stays usable while it works |
| `--guard PCT` / `--cooldown N` | pause when sustained throughput collapses from thermal or power limits |
| `--max-candidates N` | refuse an absurd keyspace *before* writing hundreds of MB to disk |

`--max` measures instead of assuming, because more workers than cores often
helps less than you would expect — on the laptop above, 12 workers reach only
**24% of linear** scaling.

## Output you can pipe

```bash
php bin/pin-crack --hash-file h.txt --full --json --out found.txt
```

Results go to **stdout**, progress to **stderr**, so `> results.txt` keeps a
clean file while the display still reaches your terminal.

<details>
<summary><code>--json</code> output shape</summary>

```json
{
  "tool": "pin-crack",
  "version": "4.0.0",
  "source": "built-in common wordlist",
  "candidates": 3469,
  "workers": 12,
  "recovered": 1,
  "targets": [
    {
      "label": "alice",
      "algorithm": "bcrypt $2y$ cost 12",
      "skipped": false,
      "found": true,
      "secret": "112358",
      "tried": 554,
      "seconds": 29.04,
      "rate_per_second": 19.08,
      "index": 45,
      "stopped": null,
      "complete": true,
      "cooldowns": 0,
      "resumed": false
    }
  ]
}
```

</details>

| Exit code | Meaning |
|:--:|---|
| `0` | something was recovered |
| `1` | nothing was — and the whole candidate list was covered |
| `2` | usage error |
| `3` | stopped early, progress saved, rerun with `--resume` |

`1` and `3` are **different answers**. `1` means the source was exhausted;
`3` means you do not know yet. Writing up a `3` as "not recoverable" is how a
finding falls apart in review.

---

## Numbers you can defend

Every design decision that looks paranoid in this codebase is there so a
result survives a client pushing back.

- **`[BURST]` is labelled.** A ~20 s benchmark still runs inside turbo boost.
  On one test machine a 400 s sweep gave 8.67 H/s where the burst figure said
  22.58 H/s — a 2.6× gap, the difference between "an afternoon" and "a day and
  a half". `--bench` prints a `sustained` estimate and tells you to prefer it.
- **The position of a hit is reported**, because "candidate 7 of 3,469" and
  "candidate 418,393 of 1,000,000" are completely different findings.
- **`--include` warns**, loudly, because front-loading candidates destroys what
  that position means. It is for confirming a secret you already know.
- **Rate and ETA are withheld for ten seconds.** Dividing a start-up burst by a
  very short interval produces "3557 H/s, 4.7 min left" for a job that is
  really hours long. A missing ETA beats a fictional one.
- **Checkpoints are refused unless hash, source, candidate count and worker
  count all match.** Silently resuming a different job would report coverage
  that never happened, and coverage is the one number this exercise exists to
  produce.
- **GPU figures are marked as estimates**, because this tool did not measure
  them.

Writing the result up: **[docs/REPORTING.md](docs/REPORTING.md)**, including the
remediation to recommend — and why "raise the bcrypt cost" is not it.

---

## How it works

```
                  ┌──────────────────────────────────────────┐
  hash + source → │  CandidateBuilder → candidates on disk   │
                  │  fixed-width records, or + offset index  │
                  └────────────────────┬─────────────────────┘
                                       │ contiguous ranges
            ┌──────────────┬───────────┴──┬──────────────┐
            ▼              ▼              ▼              ▼
        worker 0       worker 1       worker 2  …    worker N      ← own PHP
        fseek→range    fseek→range    fseek→range    fseek→range     processes
            │              │              │              │
            └──────────────┴──────┬───────┴──────────────┘
                                  │ atomic JSON status files
                                  ▼                              (no pipes.
                     ParallelCracker (parent)                     ever.)
                     polls · rate window · guard · checkpoint
```

- **Candidates are materialised to a file**, never held in a PHP array — a
  million strings in memory blows past the default 128 MB `memory_limit`, and
  several processes need to read disjoint slices of the same list.
- **Each worker owns a contiguous range** and seeks straight to its first
  record: arithmetic for fixed-width candidates, or via a side-car file of
  64-bit offsets when a wordlist has mixed lengths. That index is only built
  when a differing length actually appears.
- **Workers report by atomically rewriting a small JSON file.** There are no
  pipes anywhere — `stream_select()` and non-blocking mode do not work on
  `proc_open` pipes on Windows, and the pipe-based version lost messages and
  hung the parent. This is the single most important decision in the codebase.
- **The target hash reaches workers in a job file**, inside a `0700` temporary
  directory, not on the command line — so client material never appears in a
  process listing.

Design, trade-offs and the bug history behind each choice:
**[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)**

---

## Honestly: should you use hashcat instead?

Often, yes.

|  | pin-crack | hashcat |
|---|---|---|
| bcrypt cost 12 throughput | ~16 H/s (this laptop, 12 threads) | ~7,000 H/s (one high-end GPU, published estimate) |
| Install | `git clone`, PHP already there | GPU drivers, OpenCL/CUDA toolchain |
| Runs on the engagement laptop | yes | if it has the GPU and the drivers |
| Runs inside a client's locked-down VM | usually | rarely |
| Rule engine, 300+ hash modes | no | yes |
| Reports candidate position, keyspace coverage, resumable per-shard state | yes | partially |

If you need raw speed, use hashcat. This tool exists for the other situation:
you have one hash, a laptop, no toolchain, and you need a defensible number by
tomorrow morning.

It will **never** grow a GPU backend, network access, or a runtime dependency.
Those are documented non-goals.

---

## Documentation

| | |
|---|---|
| [docs/USAGE.md](docs/USAGE.md) | every option, with worked examples |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | design, trade-offs, bug history |
| [docs/REPORTING.md](docs/REPORTING.md) | turning a run into a finding that holds up |
| [README.id.md](README.id.md) | ringkasan dalam Bahasa Indonesia |
| [CONTRIBUTING.md](CONTRIBUTING.md) | ground rules — Windows counts, honest output is mandatory |
| [SECURITY.md](SECURITY.md) | authorized use, handling material, reporting a vulnerability |
| [CHANGELOG.md](CHANGELOG.md) | what changed, and which bug each fix came from |

## License

MIT — see [LICENSE](LICENSE).
