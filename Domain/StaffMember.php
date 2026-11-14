<?php

declare(strict_types=1);

namespace Plugins\LoanOrganisation\Domain;

/**
 * A person posted to the business: an officer or a cashier.
 *
 * BANNED IS A KILL SWITCH, NOT A DELETE. A banned person is removed from every
 * assignment picker ({@see self::isAssignable()}), but the row stays readable,
 * because their name is already printed on receipts and handovers. Listings
 * therefore say which use they serve, and a historical lookup never filters on
 * `banned`.
 *
 * `user_id` is a plain nullable reference to the central auth user. This module
 * does not resolve it and does not depend on the User plugin for it.
 */
final class StaffMember
{
    private const string TIME_FORMAT = 'Y-m-d H:i:s';

    private function __construct(
        private readonly int $id,
        // The CENTRAL account id, which is a ULID string (users.user_id),
        // not an integer. See the note on the column in the migration.
        private ?string $userId,
        private string $name,
        private ?string $phone,
        private ?string $email,
        private StaffRole $role,
        private ?int $branchId,
        private ?string $region,
        private bool $banned,
        private ?string $hiredOn,
        private ?string $photoPath,
        private int $rev,
        private readonly string $createdAt,
        private string $updatedAt,
        private ?string $deletedAt,
    ) {
    }

    /**
     * A new person. Never created banned.
     *
     * @param array<string, mixed> $fields
     * @throws \DomainException
     */
    public static function create(int $id, array $fields, \DateTimeImmutable $now): self
    {
        $stamp = $now->format(self::TIME_FORMAT);

        $member = new self(
            id: $id,
            userId: null,
            name: '',
            phone: null,
            email: null,
            role: StaffRole::Officer,
            branchId: null,
            region: null,
            banned: false,
            hiredOn: null,
            photoPath: null,
            rev: 0,
            createdAt: $stamp,
            updatedAt: $stamp,
            deletedAt: null,
        );

        if (!array_key_exists('name', $fields) || !array_key_exists('role', $fields)) {
            throw new \DomainException('A staff member needs a name and a role.');
        }

        $member->apply($fields, $now);

        if (array_key_exists('branch_id', $fields)) {
            $member->branchId = $fields['branch_id'] === null ? null : (int) $fields['branch_id'];
        }

        return $member;
    }

    /** @param array<string, mixed> $row a `staff_members` row */
    public static function reconstitute(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            userId: Columns::text($row['user_id'] ?? null),
            name: (string) $row['name'],
            phone: Columns::text($row['phone'] ?? null),
            email: Columns::text($row['email'] ?? null),
            role: StaffRole::from((string) $row['role']),
            branchId: Columns::int($row['branch_id'] ?? null),
            region: Columns::text($row['region'] ?? null),
            banned: (bool) $row['banned'],
            hiredOn: Columns::text($row['hired_on'] ?? null),
            photoPath: Columns::text($row['photo_path'] ?? null),
            rev: (int) $row['rev'],
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
            deletedAt: Columns::text($row['deleted_at'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $changes profile fields only; posting is {@see self::assignBranch()}
     * @throws \DomainException
     */
    public function revise(array $changes, \DateTimeImmutable $now): void
    {
        $this->apply($changes, $now);
    }

    /** @return bool true when the person was not banned before this call */
    public function ban(\DateTimeImmutable $now): bool
    {
        if ($this->banned) {
            return false;
        }

        $this->banned    = true;
        $this->updatedAt = $now->format(self::TIME_FORMAT);

        return true;
    }

    /** @return bool true when the person was banned before this call */
    public function reinstate(\DateTimeImmutable $now): bool
    {
        if (!$this->banned) {
            return false;
        }

        $this->banned    = false;
        $this->updatedAt = $now->format(self::TIME_FORMAT);

        return true;
    }

    /** @return bool true when the posting actually changed */
    public function assignBranch(?int $branchId, \DateTimeImmutable $now): bool
    {
        if ($this->branchId === $branchId) {
            return false;
        }

        $this->branchId  = $branchId;
        $this->updatedAt = $now->format(self::TIME_FORMAT);

        return true;
    }

    /**
     * Whether this person may be picked for a branch, a manager seat or a
     * handover. A banned person may not; their historical records still resolve.
     */
    public function isAssignable(): bool
    {
        return !$this->banned && $this->deletedAt === null;
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

    public function branchId(): ?int
    {
        return $this->branchId;
    }

    public function isBanned(): bool
    {
        return $this->banned;
    }

    public function createdAt(): string
    {
        return $this->createdAt;
    }

    /** @return array<string, mixed> */
    public function toRow(): array
    {
        return [
            'user_id'    => $this->userId,
            'name'       => $this->name,
            'phone'      => $this->phone,
            'email'      => $this->email,
            'role'       => $this->role->value,
            'branch_id'  => $this->branchId,
            'region'     => $this->region,
            'banned'     => $this->banned ? 1 : 0,
            'hired_on'   => $this->hiredOn,
            'photo_path' => $this->photoPath,
            'rev'        => $this->rev,
            'updated_at' => $this->updatedAt,
            'deleted_at' => $this->deletedAt,
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'rev'        => $this->rev,
            'user_id'    => $this->userId,
            'name'       => $this->name,
            'phone'      => $this->phone,
            'email'      => $this->email,
            'role'       => $this->role->value,
            'branch_id'  => $this->branchId,
            'region'     => $this->region,
            'banned'     => $this->banned,
            'assignable' => $this->isAssignable(),
            'hired_on'   => $this->hiredOn,
            'photo_path' => $this->photoPath,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'deleted_at' => $this->deletedAt,
        ];
    }

    /** @param array<string, mixed> $fields */
    private function apply(array $fields, \DateTimeImmutable $now): void
    {
        if (array_key_exists('name', $fields)) {
            $name = is_string($fields['name']) ? trim($fields['name']) : '';

            if ($name === '') {
                throw new \DomainException('A staff member needs a name.');
            }

            $this->name = $name;
        }

        if (array_key_exists('role', $fields)) {
            $this->role = $fields['role'] instanceof StaffRole
                ? $fields['role']
                : StaffRole::from((string) $fields['role']);
        }

        foreach (['phone', 'email', 'region', 'hired_on', 'photo_path'] as $key) {
            if (array_key_exists($key, $fields)) {
                $this->{$this->property($key)} = $fields[$key];
            }
        }

        if (array_key_exists('user_id', $fields)) {
            $this->userId = Columns::text($fields['user_id']);
        }

        $this->updatedAt = $now->format(self::TIME_FORMAT);
    }

    private function property(string $column): string
    {
        return match ($column) {
            'hired_on'   => 'hiredOn',
            'photo_path' => 'photoPath',
            default      => $column,
        };
    }
}
