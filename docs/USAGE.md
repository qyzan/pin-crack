# Usage

Every option, with the reasoning where it matters. `php bin/pin-crack --help`
prints the short version.

## Supplying hashes

```bash
php bin/pin-crack --hash '$2y$12$...'            # one hash as an argument
php bin/pin-crack --hash-file hashes.txt         # a file, one per line
cat hashes.txt | php bin/pin-crack               # stdin
```

Quote the hash. In an unquoted shell string, `$2y$12$...` is read as shell
variables and arrives empty or mangled.

A hash file accepts `label:hash`, which is how recovered values get matched
back to accounts, and skips lines starting with `#`:

```
# exported from the staging user table, 2026-11-18
alice:$2y$12$...
bob:$2y$12$...
carol:5f4dcc3b5aa765d61d8327deb882cf99
```

The label is split on the last colon before the hash, so `user:42:$2y$...`
gives the label `user:42`.

Shell history keeps `--hash` arguments. Use `--hash-file` or stdin when that
matters.

## Hash types

bcrypt, argon2 and bare md5/sha1/sha256/sha384/sha512 are detected from the
hash itself. Anything else needs `--algo`.

```bash
# unsalted md5, detected from its 32 hex characters
php bin/pin-crack --hash 5f4dcc3b5aa765d61d8327deb882cf99 --digits 4 --full

# sha256(salt . pin)
php bin/pin-crack --hash "$STORED" --algo sha256 \
  --salt 's4lt' --salt-format '{salt}{secret}' --digits 6 --full

# sha256(pin . salt), hashed three times
php bin/pin-crack --hash "$STORED" --algo sha256 \
  --salt 's4lt' --salt-format '{secret}{salt}' --iterations 3 --digits 6 --full
```

`--salt-format` must contain `{secret}`. `--algo` accepts `bcrypt`, `argon2`,
`crypt`, or any algorithm name `hash_algos()` reports.

An unidentifiable hash is **skipped with a message**, not treated as a failed
crack, so a bad line in a hash file does not quietly become "not vulnerable".

## Candidate sources

Exactly one source is used. They do not combine, because a run has to be
reproducible.

### Built-in lists (default)

Curated frequent PINs, repeated-digit and alternating patterns, arithmetic
sequences, and birth-year shapes — ordered most likely first.

```bash
php bin/pin-crack --hash '...' --digits 6           # ~3,500 candidates
php bin/pin-crack --hash '...' --digits 6 --dates   # ~87,000, adds date shapes
php bin/pin-crack --hash '...' --digits 4
```

Curated lists exist for 4 and 6 digits. Other lengths still get patterns,
sequences and years.

### Full keyspace

```bash
php bin/pin-crack --hash '...' --full               # 10^6 for 6 digits
php bin/pin-crack --hash '...' --digits 8 --full    # 10^8, needs --max-candidates
```

### Masks

```bash
php bin/pin-crack --hash '...' --mask '19?d?d'       # 1900-1999
php bin/pin-crack --hash '...' --mask '?u?l?l?d?d?d' # the Abc123 shape
php bin/pin-crack --hash '...' --mask '?h?h?h?h'     # four hex characters
```

| Token | Set | Size |
|---|---|---|
| `?d` | `0-9` | 10 |
| `?l` | `a-z` | 26 |
| `?u` | `A-Z` | 26 |
| `?s` | space and punctuation | 33 |
| `?a` | `?l?u?d?s` | 95 |
| `?h` | `0-9a-f` | 16 |
| `?H` | `0-9A-F` | 16 |
| `??` | a literal `?` | 1 |

Any other character is a literal. `--mask` sets the candidate length itself,
so `--digits` is ignored with it.

### A wordlist file

```bash
php bin/pin-crack --hash '...' --wordlist rockyou.txt
```

The file **replaces** the built-in lists. Lines are read streaming, so the
file may be larger than memory; blank lines are skipped and candidates may be
of mixed length. Duplicates are *not* removed — deduplicating a 14-million
line list in memory would cost more than re-testing the few duplicates in it.

### Front-loading and inspection

```bash
php bin/pin-crack --hash '...' --include 1234,000000
php bin/pin-crack --save-wordlist candidates.txt --digits 6 --dates
```

`--include` puts candidates at the very front. The tool prints a warning
because it breaks what the reported position means — use it to confirm a
secret you already suspect, not to produce a figure for a report.

`--save-wordlist` writes the list and exits without touching any hash, which
is the easy way to inspect or diff what a source actually covers.

### Size limit

`--max-candidates` (default 50,000,000) refuses an oversized source before
writing anything. `0` disables the check. The list costs about
`(length + 1)` bytes per candidate on disk, plus 8 bytes per record if an
index is needed.

```bash
php bin/pin-crack --hash '...' --digits 8 --full --max-candidates 0
```

## Performance

```bash
php bin/pin-crack --hash '...' --workers 8
php bin/pin-crack --hash '...' --full --max
php bin/pin-crack --hash '...' --priority idle
php bin/pin-crack --hash '...' --bench
```

- `--workers N` defaults to the number of logical processors. With `N = 1` the
  single-process path runs, which is also the automatic fallback when
  `proc_open()` is unavailable.
- `--max` is the overnight profile: it measures the best worker count instead
  of assuming it, lowers priority, enables checkpointing and the guard.
- `--priority below|idle|normal` sets OS priority. On an idle machine this
  barely costs throughput and keeps the box usable.
- `--bench` measures and exits. Read the `sustained` line, not `[BURST]`.

## Long sweeps

```bash
php bin/pin-crack --hash '...' --full --max                     # checkpoints by default
php bin/pin-crack --hash '...' --full --max --resume            # continue
php bin/pin-crack --hash '...' --full --max --max-hours 8       # stop after 8 h
php bin/pin-crack --hash '...' --full --checkpoint run-a.json
php bin/pin-crack --hash '...' --full --no-checkpoint
```

Checkpointing is on by default with `--max` or `--full`, writing
`.pin-crack-progress.json` in the current directory. Without `--resume`, an
existing checkpoint is reported and then overwritten.

A checkpoint only resumes the job it was written for: the hash, the candidate
source, the candidate count and the worker count all have to match. Change
`--workers` and you start over.

Checkpointing applies to a single target. With several hashes in one run,
nothing is checkpointed.

```bash
php bin/pin-crack --hash '...' --full --guard 60 --cooldown 300
php bin/pin-crack --hash '...' --full --guard 0
```

The guard pauses the sweep when the rate stays below `--guard` percent of the
observed peak, for at least 60 seconds, after a five-minute warm-up, and only
while more than 2% of the work is left.

## Output

```bash
php bin/pin-crack --hash '...' --quiet              # results only
php bin/pin-crack --hash '...' --json               # machine-readable
php bin/pin-crack --hash-file h.txt --out found.txt # append label:secret
```

Results go to stdout and progress to stderr, so `> results.txt` keeps a clean
file while the progress display still reaches the terminal.

`--json` prints one object, with a `targets` array holding `secret`, `tried`,
`seconds`, `rate_per_second`, `index`, `complete`, `stopped`, `cooldowns` and
`resumed` per target.

### Exit codes

| Code | Meaning |
|---|---|
| 0 | at least one hash recovered |
| 1 | nothing recovered, and the sweep covered its whole candidate list |
| 2 | usage error |
| 3 | stopped before finishing; progress saved, use `--resume` |

1 and 3 are different answers. `1` means the source was exhausted; `3` means
you do not know yet.

```bash
php bin/pin-crack --hash "$H" --full --max --max-hours 8 --quiet
case $? in
  0) echo "recovered" ;;
  1) echo "not in the keyspace searched" ;;
  3) echo "incomplete - rerun with --resume" ;;
  *) echo "usage error" ;;
esac
```
