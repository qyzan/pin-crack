<?php

declare(strict_types=1);

namespace PinCrack\Candidates;

/**
 * Built-in candidate generators for short numeric secrets.
 *
 * The point of these lists is not to be exhaustive - `--full` already covers
 * the whole keyspace - but to show how little of it a real attacker needs to
 * try before the expected cost collapses.
 */
final class Wordlist
{
    /**
     * Most frequent PINs, ordered most common first.
     *
     * INTEGRITY NOTE: "123456" sits first because it tops every published
     * frequency analysis of leaked numeric secrets, NOT because it was moved
     * there to make a demo succeed. Keep this ordering honest: anything you
     * push to the front stops the result from representing a real dictionary
     * attack. That is also why --include prints a warning.
     *
     * @return list<string>
     */
    public static function curated(int $digits): array
    {
        $six = [
            '123456', '654321', '111111', '000000', '123123', '666666', '121212',
            '112233', '789456', '159753', '123321', '456789', '999999', '888888',
            '555555', '777777', '222222', '333333', '444444', '101010', '147258',
            '102030', '123654', '696969', '131313', '110110', '998877', '159357',
            '147852', '142536', '987654', '212121', '232323', '010101', '123000',
            '100100', '111222', '121314', '135791', '246813', '777888', '123789',
            '321654', '456123', '789123', '112358', '191919', '202020', '123457',
            '234567', '345678', '567890', '098765', '876543', '765432', '543210',
            '012345', '111000', '100000', '999000', '258369', '357951', '753159',
            '951357', '123369', '789321', '147369', '258147', '369258', '111112',
            '121121', '131415', '151515', '161616', '171717', '181818', '191817',
            '202122', '123098', '321123', '456654', '789987', '010203', '020202',
            '030303', '040404', '050505', '060606', '070707', '080808', '090909',
            '112211', '223322', '334433', '135246', '246135', '159263', '147250',
        ];

        $four = [
            '1234', '1111', '0000', '1212', '7777', '1004', '2000', '4444', '2222',
            '6969', '9999', '3333', '5555', '6666', '1122', '1313', '8888', '4321',
            '2001', '1010', '1123', '9876', '2580', '1231', '1230', '1984', '2468',
            '1357', '1478', '1593', '7410', '0101', '1221', '2112', '1991', '1990',
        ];

        return match ($digits) {
            4 => $four,
            6 => $six,
            default => [],
        };
    }

    /**
     * Expand a letter template such as "ababab" into every digit assignment.
     *
     * @return list<string>
     */
    public static function expandTemplate(string $template): array
    {
        $chars = str_split($template);
        $letters = array_values(array_unique($chars));
        $total = (int) (10 ** count($letters));
        $out = [];

        for ($i = 0; $i < $total; $i++) {
            $map = [];
            $x = $i;
            foreach ($letters as $letter) {
                $map[$letter] = (string) ($x % 10);
                $x = intdiv($x, 10);
            }
            $s = '';
            foreach ($chars as $ch) {
                $s .= $map[$ch];
            }
            $out[] = $s;
        }

        return $out;
    }

    /** @return list<string> */
    public static function patternTemplates(int $digits): array
    {
        return match ($digits) {
            6 => ['aaaaaa', 'ababab', 'aaabbb', 'abcabc', 'aabbcc', 'abccba', 'aabbaa', 'abbbba'],
            4 => ['aaaa', 'abab', 'aabb', 'abba', 'aaab', 'abbb'],
            default => [str_repeat('a', $digits)],
        };
    }

    /**
     * Ascending and descending runs with step 1, 2 and 3, wrapping at 9.
     *
     * @return list<string>
     */
    public static function sequences(int $digits): array
    {
        $out = [];
        foreach ([1, 2, 3] as $step) {
            for ($start = 0; $start < 10; $start++) {
                $asc = '';
                $desc = '';
                for ($i = 0; $i < $digits; $i++) {
                    $asc .= (string) (($start + $i * $step) % 10);
                    $desc .= (string) ((($start - $i * $step) % 10 + 10) % 10);
                }
                $out[] = $asc;
                $out[] = $desc;
            }
        }

        return $out;
    }

    /**
     * ddmmyy, mmddyy and yymmdd for every valid date in a 100-year window.
     *
     * @return list<string>
     */
    public static function dates(): array
    {
        $out = [];
        for ($y = 0; $y < 100; $y++) {
            for ($m = 1; $m <= 12; $m++) {
                $daysInMonth = (int) date('t', (int) mktime(0, 0, 0, $m, 1, 2000 + $y));
                for ($d = 1; $d <= $daysInMonth; $d++) {
                    $dd = sprintf('%02d', $d);
                    $mm = sprintf('%02d', $m);
                    $yy = sprintf('%02d', $y);
                    $out[] = $dd . $mm . $yy;
                    $out[] = $mm . $dd . $yy;
                    $out[] = $yy . $mm . $dd;
                }
            }
        }

        return $out;
    }

    /**
     * Birth-year shapes, 6 digits only.
     *
     * @return list<string>
     */
    public static function years(int $digits): array
    {
        if ($digits !== 6) {
            return [];
        }

        $out = [];
        for ($y = 1940; $y <= 2039; $y++) {
            $full = (string) $y;
            $short = substr($full, 2, 2);
            $out[] = $full . $short;
            $out[] = $short . $full;
            $out[] = '00' . $full;
            $out[] = $full . '00';
        }

        return array_values(array_filter($out, static fn (string $p): bool => strlen($p) === 6));
    }

    /**
     * The default candidate list: curated, patterns, sequences, years and
     * optionally dates, deduplicated with the most likely candidates first.
     *
     * @param list<string> $extra prepended, ahead of everything else
     * @return list<string>
     */
    public static function build(int $digits, bool $withDates = false, array $extra = []): array
    {
        $groups = [self::curated($digits)];
        foreach (self::patternTemplates($digits) as $template) {
            $groups[] = self::expandTemplate($template);
        }
        $groups[] = self::sequences($digits);
        $groups[] = self::years($digits);
        if ($withDates && $digits === 6) {
            $groups[] = self::dates();
        }

        $merged = array_merge($extra, ...$groups);
        $seen = [];
        $final = [];
        foreach ($merged as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '' || strlen($candidate) !== $digits) {
                continue;
            }
            if (!ctype_digit($candidate) || isset($seen[$candidate])) {
                continue;
            }
            $seen[$candidate] = true;
            $final[] = $candidate;
        }

        return $final;
    }
}
