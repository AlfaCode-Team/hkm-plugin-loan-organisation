<?php

declare(strict_types=1);

namespace Plugins\LoanOrganisation\API\Contracts;

use Plugins\LoanOrganisation\API\DTO\BranchInput;
use Plugins\LoanOrganisation\API\DTO\HoursInput;
use Plugins\LoanOrganisation\API\DTO\StaffInput;
use Plugins\LoanOrganisation\API\DTO\StaffQuery;

/**
 * The published face of the Organisation module: branches, the people posted to
 * them, and the hours each branch keeps.
 *
 * Every write takes a new sync rev in the same transaction as the row it stamps.
 * A "delete" does not exist here: a branch or a person is closed or banned, and
 * the row stays so that receipts and handovers which name them still resolve.
 *
 * Reads return JSON-ready arrays. Tombstoned rows are never returned.
 */
interface OrganisationServiceContract
{
    /** @return array{items: list<array<string, mixed>>} */
    public function branches(bool $includeClosed = true): array;

    /** @return array<string, mixed> */
    public function branch(int $id): array;

    /** @return array<string, mixed> a branch with no manager until one is assigned */
    public function createBranch(BranchInput $input): array;

    /** @return array<string, mixed> */
    public function updateBranch(int $id, BranchInput $input): array;

    /**
     * Post a staff member as the branch's manager, or pass null to clear it.
     *
     * @return array<string, mixed>
     */
    public function assignManager(int $branchId, ?int $staffId): array;

    /** @return array{branch_id: int, hours: ?array<string, mixed>} hours is null until set */
    public function businessHours(int $branchId): array;

    /** @return array<string, mixed> */
    public function setBusinessHours(int $branchId, HoursInput $input): array;

    /** @return array{items: list<array<string, mixed>>} */
    public function staff(StaffQuery $query): array;

    /**
     * The assignment picker: active staff only, whatever status the query asks for.
     *
     * @return array{items: list<array<string, mixed>>}
     */
    public function assignable(StaffQuery $query): array;

    /**
     * Historical lookup by id. Returns a banned person too, because their name
     * is still on receipts and handovers.
     *
     * @return array<string, mixed>
     */
    public function staffMember(int $id): array;

    /** @return array<string, mixed> */
    public function createStaff(StaffInput $input): array;

    /** @return array<string, mixed> */
    public function updateStaff(int $id, StaffInput $input): array;

    /** @return array<string, mixed> */
    public function banStaff(int $id): array;

    /** @return array<string, mixed> */
    public function reinstateStaff(int $id): array;

    /**
     * Post a person to a branch, or pass null to take them off it. Posting a
     * banned person is refused.
     *
     * @return array<string, mixed>
     */
    public function assignStaffToBranch(int $id, ?int $branchId): array;
}
