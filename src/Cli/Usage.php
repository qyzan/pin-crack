<?php

declare(strict_types=1);

namespace PinCrack\Cli;

use PinCrack\Version;

final class Usage
{
    public static function text(): string
    {
        $version = Version::NUMBER;

        return <<<TXT
        pin-crack {$version} - offline recovery of short secrets from leaked hashes

        For authorized security testing only.

        USAGE
          pin-crack --hash '<hash>'                 one hash
          pin-crack --hash-file hashes.txt          many hashes (one per line)
          cat hash.txt | pin-crack                  hashes from stdin

        CANDIDATE SOURCES
          --digits N          secret length for the built-in lists (default 6)
          --full              the whole numeric keyspace (10^N)
          --mask M            hashcat-style mask: ?d ?l ?u ?s ?a ?h ?H, ?? for '?'
                              e.g. --mask '19?d?d'  or  --mask '?u?l?l?d?d?d'
          --wordlist F        read candidates from a file (REPLACES the built-ins)
          --include A,B,C     force these candidates to the front of the list
          --dates             add ddmmyy / mmddyy / yymmdd patterns (6 digits)
          --save-wordlist F   write the candidate list to F and exit
          --max-candidates N  refuse sources larger than N (default 50000000, 0 = no limit)

        HASH HANDLING
          bcrypt and argon2 are detected automatically, as are bare md5, sha1,
          sha256, sha384 and sha512 digests.

          --algo A            force the algorithm: bcrypt, argon2, or any
                              hash() algorithm (md5, sha1, sha256, ...)
          --salt S            salt for raw digests
          --salt-format T     how the stored value was built, default "{secret}";
                              e.g. "{salt}{secret}" or "{secret}{salt}"
          --iterations N      re-hash the digest N times (default 1)

        PERFORMANCE
          --workers N         number of workers (default: logical processors)
          --max               idle-machine mode: auto-tune workers, low priority,
                              checkpointing and the throughput guard
          --priority P        below | idle | normal  (default: below with --max)
          --bench             measure throughput and exit

        SAFEGUARDS FOR LONG SWEEPS
          --checkpoint F      save progress to F
                              (default with --max / --full: .pin-crack-progress.json)
          --resume            continue from an existing checkpoint
          --no-checkpoint     disable checkpointing
          --max-hours N       stop after N hours (progress is saved)
          --guard PCT         pause when the rate stays below PCT% of the peak
                              (default 50, 0 disables)
          --cooldown N        guard pause length in seconds (default 120)

        OUTPUT
          --quiet             results only, no progress
          --json              print a machine-readable summary on stdout
          --out F             append recovered "label:secret" pairs to F
          -h, --help          this help
          -V, --version       version

        EXAMPLES
          pin-crack --hash '\$2y\$12\$...'
          pin-crack --hash '\$2y\$12\$...' --dates
          pin-crack --hash '\$2y\$12\$...' --full --max
          pin-crack --hash '\$2y\$12\$...' --full --max --resume
          pin-crack --hash '\$2y\$12\$...' --full --max --max-hours 8
          pin-crack --hash-file hashes.txt --full --json --out recovered.txt
          pin-crack --hash '5f4dcc3b5aa765d61d8327deb882cf99' --algo md5 --digits 4
          pin-crack --hash '\$2y\$12\$...' --bench

        EXIT CODES
          0  at least one hash recovered
          1  nothing recovered (the sweep finished)
          2  usage error
          3  stopped before finishing; progress saved, use --resume

        TXT;
    }
}
