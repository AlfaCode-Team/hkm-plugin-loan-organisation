<?php

declare(strict_types=1);

namespace Plugins\LoanOrganisation\API\DTO;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;

/**
 * Parsing helpers for request values. Each one either returns a typed value or
 * throws \DomainException with a message a person can act on; the DTOs collect
 * those per field and report them together as one ValidationException.
 */
final class InputText
{
    private function __construct()
    {
    }

    /** Trimmed text, or null for a blank value. */
    public static function text(mixed $raw, int $max): ?string
    {
        if ($raw === null) {
            return null;
        }

        if (!is_scalar($raw)) {
            throw new \DomainException('Must be text.');
        }

        $value = trim((string) $raw);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $max) {
            throw new \DomainException(sprintf('Must be %d characters or fewer.', $max));
        }

        return $value;
    }

    public static function email(mixed $raw): ?string
    {
        $value = self::text($raw, 191);

        if ($value !== null && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new \DomainException('Must be a valid email address.');
        }

        return $value;
    }

    /** A calendar date as YYYY-MM-DD, or null. */
    public static function date(mixed $raw): ?string
    {
        $value = self::text($raw, 10);

        if ($value === null) {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw new \DomainException('Must be a date as YYYY-MM-DD.');
        }

        return $value;
    }

    /** A clock time as HH:MM, or null. */
    public static function clock(mixed $raw): ?string
    {
        $value = self::text($raw, 5);

        if ($value !== null && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) !== 1) {
            throw new \DomainException('Must be a time as HH:MM.');
        }

        return $value;
    }

    public static function bool(mixed $raw, bool $default): bool
    {
        if ($raw === null) {
            return $default;
        }

        return match (is_string($raw) ? strtolower(trim($raw)) : $raw) {
            true, 1, '1', 'true', 'on', 'yes'   => true,
            false, 0, '0', 'false', 'off', 'no', '' => false,
            default => throw new \DomainException('Must be true or false.'),
        };
    }

    /**
     * A positive id, or null when the value is absent or blank. Used for a
     * single optional reference, so it reports immediately rather than collecting.
     *
     * @throws ValidationException
     */
    /**
     * A central account id.
     *
     * It is a ULID — 26 characters of Crockford base32 — and NOT a number, so
     * it is validated as the opaque identifier it is. Validating it as a
     * positive integer rejected every real one, and quietly accepted a
     * truncated prefix of a numeric-looking one.
     */
    public static function userId(mixed $raw, string $field): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $value = is_scalar($raw) ? trim((string) $raw) : '';

        if ($value === '' || mb_strlen($value) > 40 || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            throw new ValidationException([
                $field => 'Must be an account id of at most 40 letters, digits, underscores or hyphens.',
            ]);
        }

        return $value;
    }

    public static function positiveId(mixed $raw, string $field): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $id = is_scalar($raw) ? filter_var($raw, FILTER_VALIDATE_INT) : false;

        if ($id === false || $id < 1) {
            throw new ValidationException([$field => 'Must be a whole number greater than zero.']);
        }

        return $id;
    }
}
