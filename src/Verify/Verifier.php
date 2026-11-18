<?php

declare(strict_types=1);

namespace PinCrack\Verify;

/**
 * Checks a single candidate against one target hash.
 *
 * Implementations must be cheap to construct and safe to use in a forked or
 * re-executed worker process: a worker receives only the hash string on its
 * command line and rebuilds the verifier from it.
 */
interface Verifier
{
    public function verify(string $candidate): bool;

    /** Short human-readable description, e.g. "bcrypt $2y$ cost 12". */
    public function describe(): string;
}
