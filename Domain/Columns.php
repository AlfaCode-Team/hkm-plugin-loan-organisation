<?php

declare(strict_types=1);

namespace Plugins\LoanOrganisation\Domain;

/**
 * Reads nullable columns out of a database row without guessing.
 *
 * A NULL column stays null. In particular {@see self::int()} never turns NULL
 * into 0, because for the opening cash and float NULL means "not counted yet"
 * and 0 means "counted, and it is zero". Coercing one into the other is the
 * exact bug the schema is written to prevent.
 */
final class Columns
{
    private function __construct()
    {
    }

    public static function text(mixed $raw): ?string
    {
        return $raw === null ? null : (string) $raw;
    }

    public static function int(mixed $raw): ?int
    {
        return $raw === null ? null : (int) $raw;
    }
}
