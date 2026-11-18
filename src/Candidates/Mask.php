<?php

declare(strict_types=1);

namespace PinCrack\Candidates;

use InvalidArgumentException;

/**
 * Hashcat-style mask, so the tool can express keyspaces beyond plain digits.
 *
 *   ?d  0-9            ?l  a-z            ?u  A-Z
 *   ?s  punctuation    ?a  ?l?u?d?s       ?h  0-9a-f
 *   ?H  0-9A-F         ??  a literal '?'
 *
 * Anything else is a literal character, so `19?d?d` covers 1900-1999 and
 * `?u?l?l?d?d?d` covers a common password shape.
 *
 * Every candidate a mask produces has the same length, which is what lets
 * workers seek directly into the candidate file by record number.
 */
final class Mask
{
    private const CHARSETS = [
        'd' => '0123456789',
        'l' => 'abcdefghijklmnopqrstuvwxyz',
        'u' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
        's' => ' !"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~',
        'h' => '0123456789abcdef',
        'H' => '0123456789ABCDEF',
    ];

    /** ?a is the union of ?l, ?u, ?d and ?s, as in hashcat. */
    private const ALL_TOKEN = 'a';

    /** @var list<string> one charset per position */
    private array $positions = [];

    private int $count;

    public function __construct(private readonly string $mask)
    {
        if ($mask === '') {
            throw new InvalidArgumentException('mask is empty');
        }

        $chars = str_split($mask);
        $n = count($chars);
        for ($i = 0; $i < $n; $i++) {
            if ($chars[$i] !== '?') {
                $this->positions[] = $chars[$i];
                continue;
            }

            $next = $chars[$i + 1] ?? null;
            if ($next === null) {
                throw new InvalidArgumentException("mask ends with a dangling '?'");
            }
            $i++;

            if ($next === '?') {
                $this->positions[] = '?';
                continue;
            }

            if ($next === self::ALL_TOKEN) {
                $this->positions[] = self::CHARSETS['l'] . self::CHARSETS['u']
                    . self::CHARSETS['d'] . self::CHARSETS['s'];
                continue;
            }

            if (!isset(self::CHARSETS[$next])) {
                throw new InvalidArgumentException("unknown mask token: ?$next");
            }
            $this->positions[] = self::CHARSETS[$next];
        }

        if ($this->positions === []) {
            throw new InvalidArgumentException('mask expands to nothing');
        }

        $total = 1;
        foreach ($this->positions as $set) {
            $size = strlen($set);
            if ($size > 0 && $total > intdiv(PHP_INT_MAX, $size)) {
                throw new InvalidArgumentException('mask keyspace overflows PHP_INT_MAX');
            }
            $total *= $size;
        }
        $this->count = $total;
    }

    public function mask(): string
    {
        return $this->mask;
    }

    public function count(): int
    {
        return $this->count;
    }

    /** Candidate length in characters - identical for every candidate. */
    public function length(): int
    {
        return count($this->positions);
    }

    /**
     * The candidate at $index, counting from 0.
     *
     * Position 0 is the fastest-moving digit, which keeps the ordering stable
     * regardless of how the keyspace is sharded.
     */
    public function at(int $index): string
    {
        if ($index < 0 || $index >= $this->count) {
            throw new InvalidArgumentException('mask index out of range');
        }

        $out = '';
        foreach ($this->positions as $set) {
            $size = strlen($set);
            $out .= $set[$index % $size];
            $index = intdiv($index, $size);
        }

        return $out;
    }

    /** @return \Generator<int, string> */
    public function each(): \Generator
    {
        for ($i = 0; $i < $this->count; $i++) {
            yield $this->at($i);
        }
    }

    /** All digits of a fixed length, e.g. 6 -> "?d?d?d?d?d?d". */
    public static function digits(int $length): self
    {
        if ($length < 1) {
            throw new InvalidArgumentException('digit length must be >= 1');
        }

        return new self(str_repeat('?d', $length));
    }
}
