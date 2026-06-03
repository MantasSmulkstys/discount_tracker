<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Groups measurement units into comparable dimensions so that like is only ever
 * compared with like — litres with millilitres, grams with kilograms — while
 * weight, volume and count stay separated.
 */
final class UnitDimension
{
    /**
     * The comparable dimension a unit belongs to. "none" is a wildcard for
     * size-less products so they are never hidden from a comparison.
     */
    public static function dimension(mixed $unit): string
    {
        $u = mb_strtolower(trim((string) $unit));

        return match ($u) {
            'mg', 'g', 'kg' => 'weight',
            'ml', 'cl', 'dl', 'l' => 'volume',
            'vnt', 'pcs', 'pc', 'units' => 'count',
            '' => 'none',
            default => $u,
        };
    }

    /**
     * Whether two dimensions can be compared. "none" matches anything so a
     * product whose size never parsed is still offered as a substitute.
     */
    public static function compatible(string $a, string $b): bool
    {
        return $a === $b || $a === 'none' || $b === 'none';
    }
}
