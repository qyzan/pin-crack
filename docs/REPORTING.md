# Turning a run into a finding

This tool exists to produce one defensible number: how long a leaked hash
stands between an attacker and the secret. This page is about writing that up
so it survives a client pushing back.

## The argument

When a short numeric secret's hash leaks, three facts matter, in this order:

1. **The keyspace is small and fixed.** A 6-digit PIN has 1,000,000 possible
   values; a 4-digit one has 10,000. No property of the hash changes that.
2. **Verification is offline.** Rate limiting, lockout, anomaly detection and
   MFA all live on the server. None of them is in the path.
3. **Therefore recovery is a matter of time, not of possibility.** The only
   open question is how much time, and that is what you measured.

This is why "the hash is bcrypt cost 12" is not a mitigation and why raising
the cost is not the remediation. The remediation is not disclosing the hash,
plus a larger keyspace or a server-side verification path that can be rate
limited.

Say this explicitly in the finding. The most common reviewer objection is
"the hashing is correct", and it is true — and beside the point.

## What to put in the ticket

**Measured, from your own run:**

- the hash family and cost, as the tool reports it (`bcrypt $2y$ cost 12`)
- candidates tried, elapsed time and the throughput the run achieved
- the machine: CPU, worker count, whether it was otherwise idle
- for a hit: the position of the secret in the candidate list
- for no hit: exactly what was covered — the whole keyspace, or which source

**Extrapolated, and labelled as such:**

- expected time to recovery for the full keyspace at your measured sustained
  rate (on average, half of it)
- what better hardware changes

Never present the recovered secret itself in a shared ticket. Reference it as
evidence held separately.

## Getting numbers that hold up

**Use the sustained rate, not the burst rate.** `--bench` labels its parallel
figure `[BURST]` because a ~20 second sweep still runs inside turbo boost. On
one test machine a 400 second sweep gave 8.67 H/s where the burst figure was
22.58 H/s — a 2.6× difference, which is the difference between "an afternoon"
and "a day and a half". The best number is a real `--full` run to completion;
the `sustained` line is the estimate to use when you cannot wait.

**Report the position of the hit.** The built-in lists are ordered
most-likely-first, so "candidate 7 of 3,469" and "candidate 418,393 of
1,000,000" are completely different findings. The first says a trivial
dictionary attack works; the second says the full keyspace was needed.

**Do not use `--include` for a figure you will report.** It front-loads
candidates, so the position becomes meaningless. The tool warns when you use
it. It is for confirming a secret you already know.

**Say which keyspace you covered.** "Not found" after the built-in wordlist
and "not found" after `--full` are not the same claim. Exit code `3` means you
stopped early and do not know yet — do not write that up as "not
recoverable".

**Mark GPU numbers as estimates.** The ~7,000 H/s figure for bcrypt cost 12 on
a high-end GPU that `--bench` mentions is published literature, not something
this tool measured. Cite it as an estimate or measure it yourself.

## A worked example

```bash
# 1. Baseline the machine and record the output
php bin/pin-crack --hash "$H" --bench | tee evidence/bench.txt

# 2. Cheap pass first: does a trivial dictionary attack work?
php bin/pin-crack --hash "$H" --dates --json | tee evidence/pass-1-wordlist.json

# 3. Full keyspace, overnight, resumable, machine-readable
php bin/pin-crack --hash "$H" --full --max --max-hours 10 --json \
  | tee evidence/pass-2-full.json
```

The JSON from each pass carries `tried`, `seconds`, `rate_per_second`,
`index`, `complete` and `stopped`, which is everything the finding needs
without retyping figures from a terminal.

## Remediation to recommend

In roughly this order:

1. **Stop disclosing the hash.** If an API response, log, export or error
   message contains the stored credential column, that is the bug. A hash is
   not safe to publish just because it is a hash.
2. **Grow the keyspace.** A longer PIN, or alphanumeric, moves the cost by
   orders of magnitude. Six digits is 10⁶; six alphanumerics is about 5.7×10¹⁰.
3. **Rotate what leaked.** Anything recoverable from disclosed material should
   be treated as disclosed.
4. **Rate limit and lock out server side** — necessary for online guessing,
   and irrelevant to the offline attack. Say that, so nobody files it as the
   fix for this.
5. **Keep the hashing as it is**, if it is already a correct modern KDF.
   Raising the cost buys a constant factor against an attacker who only needs
   a million guesses.

## Handling the material

A `--checkpoint` file contains the recovered secret once there is one, and the
default path is in the current directory. `--out` files and `--hash-file`
inputs are client material. Store them with the rest of the engagement
evidence and delete them when it closes. See [../SECURITY.md](../SECURITY.md).
