# Security policy

## Intended use

pin-crack is an offline password-recovery tool. Run it only against hashes you
are authorized to test: your own systems, a documented penetration-testing
engagement, a CTF, or security research on material you own.

Using it against material you have no authorization for is likely illegal in
your jurisdiction. The license disclaims all warranty and liability.

## Handling material safely

The tool is built so that routine use does not leak the thing you are testing,
but the operator still has to do their part.

What the tool does:

- The target hash is handed to worker processes **in a job file inside a
  private temporary directory**, not on the command line, so it does not show
  up in `ps` / Task Manager output.
- Temporary candidate files, job files and status files are created in a
  directory created with `0700` and removed when the run ends.
- Recovered secrets are printed to stdout, and written to a file only when you
  pass `--out`.

What you have to do:

- `--checkpoint` files contain a recovered secret once one is found, and the
  default path is `.pin-crack-progress.json` **in the current directory**.
  `.gitignore` excludes it; delete it when the engagement is over.
- `--out` files, `--hash-file` inputs and saved wordlists are engagement
  material. Store and dispose of them accordingly.
- Shell history keeps `--hash '...'` arguments. Prefer `--hash-file` or stdin
  when that matters.
- A sweep is CPU-bound for hours. On a client-owned machine, get that in
  writing first.

## Reporting a vulnerability in pin-crack itself

Please do not open a public issue for a security problem in this tool, for
example a path-traversal in option handling or a leak of hash material into a
world-readable location.

Instead, use GitHub's **Report a vulnerability** button under the Security tab
of this repository (Security → Advisories → Report a vulnerability). Expect an
initial reply within a week.

Please include:

- what the issue is and what it exposes,
- the exact command line and PHP version,
- a minimal reproduction, using synthetic hashes only — never include real
  client material in a report.

## Supported versions

Fixes land on the latest minor release. There are no long-term support
branches.
