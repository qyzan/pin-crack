<?php

declare(strict_types=1);

namespace PinCrack\Cli;

use PinCrack\Support\Priority;
use PinCrack\Verify\DigestVerifier;

/**
 * Parsed command line.
 *
 * Deliberately hand-rolled: the tool must run from a bare `git clone` with no
 * Composer dependencies, on any PHP 8.1+.
 */
final class Options
{
    public bool $help = false;
    public bool $version = false;

    public ?string $hash = null;
    public ?string $hashFile = null;

    public int $digits = 6;
    public ?string $wordlist = null;
    public ?string $mask = null;
    /** @var list<string> */
    public array $include = [];
    public bool $dates = false;
    public bool $full = false;
    public ?string $saveWordlist = null;
    public int $maxCandidates = 50_000_000;

    public ?int $workers = null;
    public bool $max = false;
    public ?string $priority = null;
    public bool $bench = false;

    public ?string $checkpoint = null;
    public bool $noCheckpoint = false;
    public bool $resume = false;
    public float $maxHours = 0.0;
    public int $guard = 50;
    public int $cooldown = 120;

    public ?string $algo = null;
    public string $salt = '';
    public string $saltFormat = DigestVerifier::DEFAULT_TEMPLATE;
    public int $iterations = 1;

    public bool $quiet = false;
    public bool $json = false;
    public ?string $out = null;

    /** Internal: worker mode. */
    public bool $worker = false;
    public ?string $job = null;

    /**
     * @param list<string> $argv arguments without the script name
     */
    public static function parse(array $argv): self
    {
        $o = new self();
        $count = count($argv);

        for ($i = 0; $i < $count; $i++) {
            $arg = $argv[$i];
            $value = static function () use ($argv, &$i, $arg): string {
                $next = $argv[$i + 1] ?? null;
                if ($next === null) {
                    throw new UsageException("$arg needs a value");
                }
                $i++;

                return $next;
            };

            switch ($arg) {
                case '-h':
                case '--help':
                    $o->help = true;
                    break;
                case '-V':
                case '--version':
                    $o->version = true;
                    break;

                case '--hash':
                    $o->hash = $value();
                    break;
                case '--hash-file':
                    $o->hashFile = $value();
                    break;

                case '--digits':
                    $o->digits = (int) $value();
                    break;
                case '--wordlist':
                    $o->wordlist = $value();
                    break;
                case '--mask':
                    $o->mask = $value();
                    break;
                case '--include':
                    $o->include = array_values(array_filter(array_map('trim', explode(',', $value()))));
                    break;
                case '--dates':
                    $o->dates = true;
                    break;
                case '--full':
                    $o->full = true;
                    break;
                case '--save-wordlist':
                    $o->saveWordlist = $value();
                    break;
                case '--max-candidates':
                    $o->maxCandidates = max(0, (int) $value());
                    break;

                case '--workers':
                    $o->workers = max(1, (int) $value());
                    break;
                case '--max':
                    $o->max = true;
                    break;
                case '--priority':
                    $o->priority = strtolower($value());
                    break;
                case '--bench':
                    $o->bench = true;
                    break;

                case '--checkpoint':
                    $o->checkpoint = $value();
                    break;
                case '--no-checkpoint':
                    $o->noCheckpoint = true;
                    break;
                case '--resume':
                    $o->resume = true;
                    break;
                case '--max-hours':
                    $o->maxHours = (float) $value();
                    break;
                case '--guard':
                    $o->guard = (int) $value();
                    break;
                case '--cooldown':
                    $o->cooldown = max(5, (int) $value());
                    break;

                case '--algo':
                    $o->algo = strtolower($value());
                    break;
                case '--salt':
                    $o->salt = $value();
                    break;
                case '--salt-format':
                    $o->saltFormat = $value();
                    break;
                case '--iterations':
                    $o->iterations = max(1, (int) $value());
                    break;

                case '--quiet':
                    $o->quiet = true;
                    break;
                case '--json':
                    $o->json = true;
                    break;
                case '--out':
                    $o->out = $value();
                    break;

                case '--worker':
                    $o->worker = true;
                    break;
                case '--job':
                    $o->job = $value();
                    break;

                default:
                    throw new UsageException("unknown option: $arg  (try --help)");
            }
        }

        return $o;
    }

    public function validate(): void
    {
        if ($this->worker) {
            if ($this->job === null) {
                throw new UsageException('worker mode needs --job');
            }

            return;
        }

        if ($this->digits < 3 || $this->digits > 12) {
            throw new UsageException('--digits must be between 3 and 12');
        }

        if ($this->priority !== null && !Priority::isValid($this->priority)) {
            throw new UsageException('--priority must be below, idle or normal');
        }

        if ($this->mask !== null && ($this->full || $this->wordlist !== null)) {
            throw new UsageException('--mask cannot be combined with --full or --wordlist');
        }

        if ($this->full && $this->wordlist !== null) {
            throw new UsageException('--full cannot be combined with --wordlist');
        }

        if ($this->wordlist !== null && !is_file($this->wordlist)) {
            throw new UsageException("wordlist not found: $this->wordlist");
        }

        if ($this->hashFile !== null && !is_file($this->hashFile)) {
            throw new UsageException("hash file not found: $this->hashFile");
        }

        if ($this->salt !== '' && !str_contains($this->saltFormat, '{secret}')) {
            throw new UsageException('--salt-format must contain {secret}');
        }

        if ($this->maxHours < 0) {
            throw new UsageException('--max-hours cannot be negative');
        }

        if ($this->guard < 0 || $this->guard > 100) {
            throw new UsageException('--guard must be between 0 and 100');
        }
    }

    public function effectivePriority(): string
    {
        if ($this->priority !== null) {
            return $this->priority;
        }

        return $this->max ? Priority::BELOW : Priority::NORMAL;
    }

    /** @return array<string, mixed> */
    public function verifierOptions(): array
    {
        return [
            'algo' => $this->algo,
            'salt' => $this->salt,
            'salt_format' => $this->saltFormat,
            'iterations' => $this->iterations,
        ];
    }
}
