<?php

declare(strict_types=1);

namespace Plugins\LoanOrganisation\Domain;

/**
 * A location the business trades from.
 *
 * THE MANAGER REFERENCE IS NULLABLE ON PURPOSE. A branch and its manager refer to
 * each other (the manager is a staff member posted here), so requiring both at
 * once makes the first row impossible to insert. A branch is created with no
 * manager and one is assigned afterwards; transferring the manager out clears
 * it again. Nothing in this class lets a manager be set without that check
 * having been made by the service, which knows what staff are posted here.
 *
 * MONEY IS THREE-STATE. `openingCash` and `openingFloat` are null until counted,
 * and a counted zero is stored as 0. {@see OpeningAmount}.
 */
final class Branch
{
    private const string TIME_FORMAT = 'Y-m-d H:i:s';

    private function __construct(
        private readonly int $id,
        private string $name,
        private ?string $code,
        private ?string $district,
        private ?string $address,
        private ?string $phone,
        private ?int $managerStaffId,
        private ?string $openedOn,
        private BranchStatus $status,
        private ?int $openingCash,
        private ?int $openingFloat,
        private ?string $moneySetAt,
        private int $rev,
        private readonly string $createdAt,
        private string $updatedAt,
        private ?string $deletedAt,
    ) {
    }

    /**
     * A new branch. It has no manager: that is assigned later, on purpose.
     *
     * @param array<string, mixed> $fields only the keys the caller supplied
     * @throws \DomainException
     */
    public static function create(int $id, array $fields, \DateTimeImmutable $now): self
    {
        $stamp = $now->format(self::TIME_FORMAT);

        $branch = new self(
            id: $id,
            name: '',
            code: null,
            district: null,
            address: null,
            phone: null,
            managerStaffId: null,
            openedOn: null,
            status: BranchStatus::Open,
            openingCash: null,
            openingFloat: null,
            moneySetAt: null,
            rev: 0,
            createdAt: $stamp,
            updatedAt: $stamp,
            deletedAt: null,
        );

        if (!array_key_exists('name', $fields)) {
            throw new \DomainException('A branch needs a name.');
        }

        $branch->apply($fields, $now);

        return $branch;
    }

    /**
     * @param array<string, mixed> $row a `branches` row
     */
    public static function reconstitute(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            name: (string) $row['name'],
            code: Columns::text($row['code'] ?? null),
            district: Columns::text($row['district'] ?? null),
            address: Columns::text($row['address'] ?? null),
            phone: Columns::text($row['phone'] ?? null),
            managerStaffId: Columns::int($row['manager_staff_id'] ?? null),
            openedOn: Columns::text($row['opened_on'] ?? null),
            status: BranchStatus::from((string) $row['status']),
            openingCash: Columns::int($row['opening_cash'] ?? null),
            openingFloat: Columns::int($row['opening_float'] ?? null),
            moneySetAt: Columns::text($row['money_set_at'] ?? null),
            rev: (int) $row['rev'],
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
            deletedAt: Columns::text($row['deleted_at'] ?? null),
        );
    }

    /**
     * Apply a partial change. Only keys present in $changes are touched, so an
     * update that omits `opening_cash` leaves it alone, while one that sends
     * `opening_cash: null` marks it as not counted again.
     *
     * @param array<string, mixed> $changes
     * @throws \DomainException
     */
    public function revise(array $changes, \DateTimeImmutable $now): void
    {
        $this->apply($changes, $now);
    }

    /**
     * Post a staff member here as manager. The service has already checked that
     * the person is an active member posted to THIS branch.
     */
    public function assignManager(?int $staffId, \DateTimeImmutable $now): void
    {
        $this->managerStaffId = $staffId;
        $this->updatedAt      = $now->format(self::TIME_FORMAT);
    }

    /** Clear the manager, because they left the branch or were banned. */
    public function clearManager(\DateTimeImmutable $now): bool
    {
        if ($this->managerStaffId === null) {
            return false;
        }

        $this->managerStaffId = null;
        $this->updatedAt      = $now->format(self::TIME_FORMAT);

        return true;
    }

    public function isManager(int $staffId): bool
    {
        return $this->managerStaffId === $staffId;
    }

    public function stampRev(int $rev): void
    {
        $this->rev = $rev;
    }

    public function id(): int
    {
        return $this->id;
    }

    public function rev(): int
    {
        return $this->rev;
    }

    public function managerStaffId(): ?int
    {
        return $this->managerStaffId;
    }

    public function openingCash(): ?int
    {
        return $this->openingCash;
    }

    public function openingFloat(): ?int
    {
        return $this->openingFloat;
    }

    public function status(): BranchStatus
    {
        return $this->status;
    }

    public function createdAt(): string
    {
        return $this->createdAt;
    }

    /**
     * The mutable columns plus the bookkeeping the write must carry.
     * `id`, `created_at` and `device_id` are the repository's to set on insert.
     *
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'name'             => $this->name,
            'code'             => $this->code,
            'district'         => $this->district,
            'address'          => $this->address,
            'phone'            => $this->phone,
            'manager_staff_id' => $this->managerStaffId,
            'opened_on'        => $this->openedOn,
            'status'           => $this->status->value,
            'opening_cash'     => $this->openingCash,
            'opening_float'    => $this->openingFloat,
            'money_set_at'     => $this->moneySetAt,
            'rev'              => $this->rev,
            'updated_at'       => $this->updatedAt,
            'deleted_at'       => $this->deletedAt,
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'               => $this->id,
            'rev'              => $this->rev,
            'name'             => $this->name,
            'code'             => $this->code,
            'district'         => $this->district,
            'address'          => $this->address,
            'phone'            => $this->phone,
            'manager_staff_id' => $this->managerStaffId,
            'opened_on'        => $this->openedOn,
            'status'           => $this->status->value,
            // null = not counted yet. 0 = counted and it is zero. Never coerced.
            'opening_cash'     => $this->openingCash,
            'opening_float'    => $this->openingFloat,
            'money_set_at'     => $this->moneySetAt,
            'created_at'       => $this->createdAt,
            'updated_at'       => $this->updatedAt,
            'deleted_at'       => $this->deletedAt,
        ];
    }

    /** @param array<string, mixed> $fields */
    private function apply(array $fields, \DateTimeImmutable $now): void
    {
        $stamp = $now->format(self::TIME_FORMAT);

        if (array_key_exists('name', $fields)) {
            $this->name = self::nameOf($fields['name']);
        }

        if (array_key_exists('code', $fields)) {
            $this->code = $fields['code'];
        }

        if (array_key_exists('district', $fields)) {
            $this->district = $fields['district'];
        }

        if (array_key_exists('address', $fields)) {
            $this->address = $fields['address'];
        }

        if (array_key_exists('phone', $fields)) {
            $this->phone = $fields['phone'];
        }

        if (array_key_exists('opened_on', $fields)) {
            $this->openedOn = $fields['opened_on'];
        }

        if (array_key_exists('status', $fields)) {
            $this->status = $fields['status'] instanceof BranchStatus
                ? $fields['status']
                : BranchStatus::from((string) $fields['status']);
        }

        if (array_key_exists('opening_cash', $fields)) {
            $this->openingCash = OpeningAmount::from($fields['opening_cash'], 'opening_cash');
        }

        if (array_key_exists('opening_float', $fields)) {
            $this->openingFloat = OpeningAmount::from($fields['opening_float'], 'opening_float');
        }

        // The first time either figure is actually counted, the moment is kept.
        // It is never moved afterwards, so "when was this branch set up" stays answerable.
        if ($this->moneySetAt === null && ($this->openingCash !== null || $this->openingFloat !== null)) {
            $this->moneySetAt = $stamp;
        }

        $this->updatedAt = $stamp;
    }

    private static function nameOf(mixed $raw): string
    {
        $name = is_string($raw) ? trim($raw) : '';

        if ($name === '') {
            throw new \DomainException('A branch needs a name.');
        }

        return $name;
    }
}
