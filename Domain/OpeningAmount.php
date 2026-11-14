<?php

declare(strict_types=1);

namespace Plugins\LoanOrganisation\Domain;

/**
 * An opening cash or opening float figure, in whole Ugandan shillings.
 *
 * Three answers are possible and they are kept apart:
 *
 *   null  "not counted yet" — the one-time prompt is still owed. Stored as NULL.
 *   0     "counted, and it is zero" — a real answer. Stored as 0.
 *   n     a positive whole amount.
 *
 * Input is accepted as an integer or a string of digits only. Floats are refused
 * outright rather than rounded: a phone that sends 1500.5 has a bug, and
 * rounding it would hide that bug inside a figure printed on a handover.
 * An empty string is treated as "not counted", because that is what a blank
 * form field means.
 */
final class OpeningAmount
{
    /** Largest figure accepted: well inside PHP_INT_MAX, and far beyond any till. */
    private const int MAX_DIGITS = 15;

    private function __construct()
    {
    }

    /**
     * @throws \DomainException when the value is not a whole, non-negative amount
     */
    public static function from(mixed $raw, string $field): ?int
    {
        if ($raw === null) {
            return null;
        }

        if (is_int($raw)) {
            if ($raw < 0) {
                throw new \DomainException(sprintf('%s cannot be negative.', $field));
            }

            return $raw;
        }

        if (is_string($raw)) {
            $raw = trim($raw);

            if ($raw === '') {
                return null;
            }

            if (!ctype_digit($raw) || strlen($raw) > self::MAX_DIGITS) {
                throw new \DomainException(sprintf('%s must be whole shillings, zero or more.', $field));
            }

            return (int) $raw;
        }

        throw new \DomainException(sprintf('%s must be whole shillings, not a decimal.', $field));
    }
}
