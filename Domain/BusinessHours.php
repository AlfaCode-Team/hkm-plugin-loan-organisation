<?php

declare(strict_types=1);

namespace Plugins\LoanOrganisation\Domain;

/**
 * The days and times a branch keeps its desk open, one row per branch.
 *
 * `days` is exactly seven entries, one per weekday, in calendar order. A day is
 * either closed or has an opening and closing time, and the closing time must
 * come after the opening time on the same day. Overnight trading is not a
 * shape this table describes, and it is refused rather than stored wrongly.
 */
final class BusinessHours
{
    /** @var list<string> */
    public const array DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    private const string CLOCK = '/^([01]\d|2[0-3]):[0-5]\d$/';
    private const string TIME_FORMAT = 'Y-m-d H:i:s';

    /**
     * @param list<array{day: string, open: ?string, close: ?string, closed: bool}> $days
     */
    private function __construct(
        private readonly int $id,
        private readonly int $branchId,
        private array $days,
        private ?string $openingTime,
        private ?string $closingTime,
        private bool $closeReminder,
        private int $rev,
        private readonly string $createdAt,
        private string $updatedAt,
        private ?string $deletedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $fields days, opening_time, closing_time, close_reminder
     * @throws \DomainException
     */
    public static function create(int $id, int $branchId, array $fields, \DateTimeImmutable $now): self
    {
        $stamp = $now->format(self::TIME_FORMAT);

        return new self(
            id: $id,
            branchId: $branchId,
            days: self::normaliseDays($fields['days'] ?? null),
            openingTime: self::clockOrNull($fields['opening_time'] ?? null, 'opening_time'),
            closingTime: self::clockOrNull($fields['closing_time'] ?? null, 'closing_time'),
            closeReminder: (bool) ($fields['close_reminder'] ?? true),
            rev: 0,
            createdAt: $stamp,
            updatedAt: $stamp,
            deletedAt: null,
        );
    }

    /** @param array<string, mixed> $row a `business_hours` row */
    public static function reconstitute(array $row): self
    {
        $days = json_decode((string) $row['days'], true);

        return new self(
            id: (int) $row['id'],
            branchId: (int) $row['branch_id'],
            days: is_array($days) ? self::normaliseDays($days) : [],
            openingTime: Columns::text($row['opening_time'] ?? null),
            closingTime: Columns::text($row['closing_time'] ?? null),
            closeReminder: (bool) $row['close_reminder'],
            rev: (int) $row['rev'],
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
            deletedAt: Columns::text($row['deleted_at'] ?? null),
        );
    }

    /**
     * Replace the schedule. Hours are always sent whole, so this is a full
     * replacement rather than a merge, and it clears a tombstone if one exists.
     *
     * @param array<string, mixed> $fields
     * @throws \DomainException
     */
    public function revise(array $fields, \DateTimeImmutable $now): void
    {
        $this->days          = self::normaliseDays($fields['days'] ?? null);
        $this->openingTime   = self::clockOrNull($fields['opening_time'] ?? null, 'opening_time');
        $this->closingTime   = self::clockOrNull($fields['closing_time'] ?? null, 'closing_time');
        $this->closeReminder = (bool) ($fields['close_reminder'] ?? true);
        $this->deletedAt     = null;
        $this->updatedAt     = $now->format(self::TIME_FORMAT);
    }

    /**
     * Validate and put a seven-day schedule into calendar order.
     *
     * @return list<array{day: string, open: ?string, close: ?string, closed: bool}>
     * @throws \DomainException
     */
    public static function normaliseDays(mixed $raw): array
    {
        if (!is_array($raw) || count($raw) !== count(self::DAYS)) {
            throw new \DomainException('Hours must list all seven days.');
        }

        /** @var array<string, array{day: string, open: ?string, close: ?string, closed: bool}> $byDay */
        $byDay = [];

        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                throw new \DomainException('Each day must be an object.');
            }

            $day = is_string($entry['day'] ?? null) ? strtolower($entry['day']) : '';

            if (!in_array($day, self::DAYS, true)) {
                throw new \DomainException('Each day must be one of mon, tue, wed, thu, fri, sat, sun.');
            }

            if (isset($byDay[$day])) {
                throw new \DomainException(sprintf('%s is listed twice.', $day));
            }

            $closed = (bool) ($entry['closed'] ?? false);

            if ($closed) {
                $byDay[$day] = ['day' => $day, 'open' => null, 'close' => null, 'closed' => true];

                continue;
            }

            $open  = self::clockOrNull($entry['open'] ?? null, $day . '.open');
            $close = self::clockOrNull($entry['close'] ?? null, $day . '.close');

            if ($open === null || $close === null) {
                throw new \DomainException(sprintf('%s needs an opening and a closing time, or to be marked closed.', $day));
            }

            if ($close <= $open) {
                throw new \DomainException(sprintf('%s must close after it opens.', $day));
            }

            $byDay[$day] = ['day' => $day, 'open' => $open, 'close' => $close, 'closed' => false];
        }

        $ordered = [];

        foreach (self::DAYS as $day) {
            $ordered[] = $byDay[$day];
        }

        return $ordered;
    }

    public function id(): int
    {
        return $this->id;
    }

    public function rev(): int
    {
        return $this->rev;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    public function stampRev(int $rev): void
    {
        $this->rev = $rev;
    }

    public function createdAt(): string
    {
        return $this->createdAt;
    }

    /** @return array<string, mixed> */
    public function toRow(): array
    {
        return [
            'branch_id'      => $this->branchId,
            'days'           => json_encode($this->days, JSON_THROW_ON_ERROR),
            'opening_time'   => $this->openingTime,
            'closing_time'   => $this->closingTime,
            'close_reminder' => $this->closeReminder ? 1 : 0,
            'rev'            => $this->rev,
            'updated_at'     => $this->updatedAt,
            'deleted_at'     => $this->deletedAt,
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'             => $this->id,
            'branch_id'      => $this->branchId,
            'rev'            => $this->rev,
            'days'           => $this->days,
            'opening_time'   => $this->openingTime,
            'closing_time'   => $this->closingTime,
            'close_reminder' => $this->closeReminder,
            'created_at'     => $this->createdAt,
            'updated_at'     => $this->updatedAt,
            'deleted_at'     => $this->deletedAt,
        ];
    }

    private static function clockOrNull(mixed $raw, string $field): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (!is_string($raw) || preg_match(self::CLOCK, $raw) !== 1) {
            throw new \DomainException(sprintf('%s must be a time as HH:MM.', $field));
        }

        return $raw;
    }
}
