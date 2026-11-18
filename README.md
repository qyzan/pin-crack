# pin-crack

Offline recovery of **short secrets** — PINs, numeric passcodes, 4–8 character
passwords — from leaked password hashes, written in dependency-free PHP.

The point of this tool is not that the hashing is weak. It usually is not.
The point is the **keyspace**:

> A 6-digit PIN has 10⁶ = 1,000,000 possible values. Correct, salted bcrypt at
> cost 12 does not change that number. It only changes how long a guaranteed
> recovery takes — and because the recovery runs offline, no server-side rate
> limit, lockout or MFA prompt is in the way.

That is the measurement this tool produces: given one leaked hash, how much
wall-clock time stands between an attacker and the secret. It is the number
that turns "a hash leaked, but it is bcrypt" into a decision.

> **For authorized security testing only.** See [SECURITY.md](SECURITY.md).

[![CI](https://github.com/qyzan/pin-crack/actions/workflows/ci.yml/badge.svg)](https://github.com/qyzan/pin-crack/actions/workflows/ci.yml)

---

## Install

Requires PHP 8.1 or newer. Nothing else.

```bash
git clone https://github.com/qyzan/pin-crack.git
cd pin-crack
php bin/pin-crack --help
```

Composer is optional and only needed for the test suite:

```bash
composer install
composer check      # phpstan + phpunit
```

Or install it as a dependency:

```bash
composer require --dev qyzan/pin-crack
vendor/bin/pin-crack --help
```

## Quick start

```bash
# Common-PIN wordlist, auto-detected hash type, one worker per core
php bin/pin-crack --hash '$2y$12$...'

# Whole 6-digit keyspace on an idle machine, overnight, resumable
php bin/pin-crack --hash '$2y$12$...' --full --max

# Pick up where it left off
php bin/pin-crack --hash '$2y$12$...' --full --max --resume

# Many hashes, machine-readable output, recovered values appended to a file
php bin/pin-crack --hash-file hashes.txt --full --json --out recovered.txt

# How long would the full keyspace take on this machine?
php bin/pin-crack --hash '$2y$12$...' --bench
```

## What it handles

| Hash family | Detection | Notes |
|---|---|---|
| bcrypt (`$2$`, `$2a$`, `$2b$`, `$2x$`, `$2y$`) | automatic | cost read from the hash |
| argon2 (`$argon2i$`, `$argon2d$`, `$argon2id$`) | automatic | parameters read from the hash |
| md5, sha1, sha256, sha384, sha512 | automatic by length | unsalted |
| the same digests, salted | `--algo` + `--salt` + `--salt-format` | e.g. `'{salt}{secret}'` |
| anything else | `--algo <name>` | any algorithm `hash_algos()` lists |

## Candidate sources

```bash
--digits 6              # built-in lists for this length (default)
--dates                 # add ddmmyy / mmddyy / yymmdd
--full                  # the whole numeric keyspace, 10^N
--mask '19?d?d'         # hashcat-style mask
--wordlist rockyou.txt  # a file (REPLACES the built-in lists)
--include 1234,0000     # force candidates to the front
--save-wordlist wl.txt  # write the list and exit, without touching any hash
```

Mask tokens: `?d` digits, `?l` lower, `?u` upper, `?s` symbols, `?a` all of
those, `?h`/`?H` hex, `??` a literal `?`. Everything else is a literal, so
`--mask '?u?l?l?d?d?d'` covers the `Abc123` shape.

The built-in lists are ordered most-likely-first, and the position of a hit is
reported, so a result reads as "candidate 1 of 3,469" rather than just "found".
That ordering is what makes the output evidence rather than a demo —
`--include` prints a warning precisely because it breaks it.

## Long sweeps

A full 6-digit sweep against cost-12 bcrypt is an overnight job on a laptop,
so the tool is built to survive one:

| Flag | What it does |
|---|---|
| `--max` | idle-machine mode: auto-tunes the worker count, lowers priority, enables checkpointing and the guard |
| `--workers N` | fixed worker count (default: logical processors) |
| `--priority below\|idle\|normal` | OS priority, so the machine stays usable |
| `--checkpoint F` / `--resume` | progress survives a reboot |
| `--max-hours N` | stop cleanly after N hours, progress saved |
| `--guard PCT` / `--cooldown N` | pause when sustained throughput collapses (thermal/power limits) |

`--max` measures the best worker count instead of assuming it, because more
workers than cores often helps less than expected, and on some laptops hurts.

## Output

```
[alice] bcrypt $2y$ cost 12
  >> RECOVERED : 418392
     position  : candidate 418,393 of 1,000,000
     tried     : 418,401 candidates
     time      : 3.7 h
     throughput: 31.29 H/s
```

With `--json` the same run prints one object per target with `secret`,
`tried`, `seconds`, `rate_per_second`, `index`, `complete` and `stopped`,
which is what you want in a report pipeline.

Exit codes: `0` something was recovered, `1` nothing was (sweep finished),
`2` usage error, `3` stopped early with progress saved.

## How it works

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for the design and the
reasons behind it. The short version:

- Candidates are **materialised to a file**, never held in a PHP array — a
  million strings in memory blows past the default `memory_limit`.
- Each worker owns a **contiguous range** of that file and seeks straight to
  its first record (fixed-width records, or a side-car offset index when the
  candidates have mixed lengths).
- Workers report progress by **atomically rewriting a small JSON file**. There
  are no pipes anywhere, because `stream_select()` does not work on
  `proc_open` pipes on Windows, and the pipe-based version lost messages and
  hung.
- The target hash is passed to workers **in a job file, not on the command
  line**, so it does not appear in the machine's process list.

## Not a GPU cracker

This is a PHP tool. One modern CPU core does roughly 25–30 bcrypt cost-12
verifications per second; a high-end GPU is in the order of 7,000. If you need
raw speed, feed the hash to hashcat. What this tool gives you instead is
something you can run on the engagement laptop, in the client's environment,
with no toolchain, and get a defensible number out of.

## Documentation

- [docs/USAGE.md](docs/USAGE.md) — every option, with examples
- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — design and trade-offs
- [docs/REPORTING.md](docs/REPORTING.md) — turning a run into a finding
- [README.id.md](README.id.md) — ringkasan dalam Bahasa Indonesia
- [CONTRIBUTING.md](CONTRIBUTING.md), [SECURITY.md](SECURITY.md), [CHANGELOG.md](CHANGELOG.md)

## License

MIT — see [LICENSE](LICENSE).
