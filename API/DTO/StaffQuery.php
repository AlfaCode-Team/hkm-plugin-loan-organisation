<?php

declare(strict_types=1);

namespace Plugins\LoanOrganisation\API\DTO;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use Plugins\LoanOrganisation\Domain\StaffRole;

/**
 * A staff listing request. The `status` filter says WHICH use the list serves:
 *
 *   active  (default) — people who may be assigned: not banned. Assignment
 *                       pickers and manager choices read this.
 *   banned            — people kept out of assignment. Shown for review and
 *                       reinstatement, never offered as a choice.
 *   all               — everyone on file, for a directory or a report that must
 *                       still name a person who has left.
 *
 * Tombstoned rows are never returned by any status.
 */
final readonly class StaffQuery
{
    public const string ACTIVE = 'active';
    public const string BANNED = 'banned';
    public const string ALL    = 'all';

    private function __construct(
        public string $status,
        public ?int $branchId,
        public ?StaffRole $role,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     * @throws ValidationException
     */
    public static function fromQuery(array $query): self
    {
        $errors = [];

        $status = isset($query['status']) && is_string($query['status'])
            ? strtolower(trim($query['status']))
            : self::ACTIVE;

        if (!in_array($status, [self::ACTIVE, self::BANNED, self::ALL], true)) {
            $errors['status'] = 'Status must be active, banned or all.';
        }

        $role = null;

        if (isset($query['role']) && is_string($query['role']) && $query['role'] !== '') {
            $role = StaffRole::tryFrom(strtolower(trim($query['role'])));

            if ($role === null) {
                $errors['role'] = 'Role must be officer or cashier.';
            }
        }

        try {
            $branchId = InputText::positiveId($query['branch_id'] ?? null, 'branch_id');
        } catch (ValidationException $e) {
            $errors['branch_id'] = $e->errors['branch_id'];
            $branchId = null;
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new self($status, $branchId, $role);
    }

    public static function active(?int $branchId = null, ?StaffRole $role = null): self
    {
        return new self(self::ACTIVE, $branchId, $role);
    }
}
