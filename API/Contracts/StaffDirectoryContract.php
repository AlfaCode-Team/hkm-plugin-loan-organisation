<?php

declare(strict_types=1);

namespace Plugins\LoanOrganisation\API\Contracts;

/**
 * Translates a signed-in USER into the STAFF MEMBER they are.
 *
 * WHY THIS EXISTS
 * ---------------
 * `recorded_by` on a payment, an expense, a handover or a visit is a
 * `staff_members.id`. `Identity->userId` is a `users.id`. They are different
 * rows in different tables, and both are numeric BIGINTs — so writing one
 * where the other belongs throws nothing, fails no constraint, and produces a
 * ledger that attributes a cashier's takings to whoever happens to hold that
 * number in the staff table.
 *
 * That is the worst shape a bug can take in a system about money: silent,
 * plausible, and only discovered when somebody is accused of a shortfall they
 * had nothing to do with.
 *
 * A module that stamps `recorded_by` therefore declares
 * `requires: ["organisation.structure"]` and resolves through this contract
 * rather than assuming the two ids are interchangeable.
 */
interface StaffDirectoryContract
{
    /**
     * The staff member id for a signed-in user, or null when that user has no
     * staff record in this business.
     *
     * Null is a real answer, not an error: a platform administrator or a
     * support engineer can be signed in without being on anyone's payroll.
     * The caller decides whether that is allowed to record anything.
     */
    public function staffIdForUser(string $userId): ?int;

    /**
     * The staff member's display name, for the `*_name` columns that are
     * denormalised onto records so a receipt still reads correctly years later
     * even if the person has since left.
     */
    public function staffName(int $staffId): ?string;

    /** Their role, for the `*_role` columns, or null if unknown. */
    public function staffRole(int $staffId): ?string;
}
