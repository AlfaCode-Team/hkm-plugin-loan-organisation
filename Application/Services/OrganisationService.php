<?php

declare(strict_types=1);

namespace Plugins\LoanOrganisation\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use Plugins\LoanOrganisation\API\Contracts\OrganisationServiceContract;
use Plugins\LoanOrganisation\API\DTO\BranchInput;
use Plugins\LoanOrganisation\API\DTO\HoursInput;
use Plugins\LoanOrganisation\API\DTO\StaffInput;
use Plugins\LoanOrganisation\API\DTO\StaffQuery;
use Plugins\LoanOrganisation\API\Exceptions\OrganisationNotFoundException;
use Plugins\LoanOrganisation\Domain\Branch;
use Plugins\LoanOrganisation\Domain\BusinessHours;
use Plugins\LoanOrganisation\Domain\StaffMember;
use Plugins\LoanOrganisation\Infrastructure\Persistence\BranchRepository;
use Plugins\LoanOrganisation\Infrastructure\Persistence\BusinessHoursRepository;
use Plugins\LoanOrganisation\Infrastructure\Persistence\IdAllocator;
use Plugins\LoanOrganisation\Infrastructure\Persistence\StaffRepository;
use Plugins\LoanSync\API\Contracts\SyncRevisionContract;

/**
 * Branch, staff and hours writes and reads.
 *
 * Every write follows one shape, and the order is the point:
 *
 *   begin
 *     rev = revisions->next()      FIRST: the rev lock orders this write against others
 *     row = read / mint id         INSIDE the lock, so two writers cannot mint the same id
 *     save(row stamped with rev)
 *   commit                         the rev and the row land together, or neither does
 *
 * A write that turns out to change nothing (banning someone already banned,
 * saving an edit with no fields) rolls back instead of committing. The rollback
 * also returns the counter, so a no-op never burns a rev no handset will see.
 *
 * Two cross-row rules are enforced here because only the service can see both
 * sides of them:
 *
 *   - A branch's manager must be an active member posted to THAT branch. Posting
 *     someone elsewhere, or banning them, clears the manager seat they held.
 *   - A banned person cannot be posted to a branch.
 *
 * Each row written takes its own rev, including a manager cleared as a side effect.
 */
final class OrganisationService implements OrganisationServiceContract
{
    public function __construct(
        private readonly BranchRepository $branches,
        private readonly StaffRepository $staff,
        private readonly BusinessHoursRepository $hours,
        private readonly IdAllocator $ids,
        private readonly SyncRevisionContract $revisions,
        private readonly TransactionManager $transaction,
    ) {
    }

    public function branches(bool $includeClosed = true): array
    {
        return [
            'items' => array_map(
                static fn(Branch $b): array => $b->toArray(),
                $this->branches->all($includeClosed),
            ),
        ];
    }

    public function branch(int $id): array
    {
        return $this->requireBranch($id)->toArray();
    }

    public function createBranch(BranchInput $input): array
    {
        $now = $this->now();

        /** @var Branch $branch */
        $branch = $this->unit('branch.create', function () use ($input, $now): array {
            $rev    = $this->revisions->next();
            $id     = $this->ids->reserve('branches');
            $branch = Branch::create($id, $input->changes(), $now);

            $branch->stampRev($rev);
            $this->branches->insert($branch);

            return [$branch, true];
        });

        return $branch->toArray();
    }

    public function updateBranch(int $id, BranchInput $input): array
    {
        $now = $this->now();

        /** @var Branch $branch */
        $branch = $this->unit('branch.update', function () use ($id, $input, $now): array {
            $rev    = $this->revisions->next();
            $branch = $this->requireBranch($id);

            if ($input->changes() === []) {
                return [$branch, false];
            }

            $branch->revise($input->changes(), $now);
            $branch->stampRev($rev);
            $this->branches->save($branch);

            return [$branch, true];
        });

        return $branch->toArray();
    }

    public function assignManager(int $branchId, ?int $staffId): array
    {
        $now = $this->now();

        /** @var Branch $branch */
        $branch = $this->unit('branch.manager', function () use ($branchId, $staffId, $now): array {
            $rev    = $this->revisions->next();
            $branch = $this->requireBranch($branchId);

            if ($staffId !== null) {
                $member = $this->staff->find($staffId);

                // Posted HERE and not banned. A manager posted elsewhere would
                // hold a seat at a branch they do not work at.
                if ($member === null || !$member->isAssignable() || $member->branchId() !== $branchId) {
                    throw new ValidationException([
                        'staff_id' => 'Only an active member posted to this branch can manage it.',
                    ]);
                }
            }

            if ($branch->managerStaffId() === $staffId) {
                return [$branch, false];
            }

            $branch->assignManager($staffId, $now);
            $branch->stampRev($rev);
            $this->branches->save($branch);

            return [$branch, true];
        });

        return $branch->toArray();
    }

    public function businessHours(int $branchId): array
    {
        $this->requireBranch($branchId);

        $hours = $this->hours->findForBranch($branchId);

        return ['branch_id' => $branchId, 'hours' => $hours?->toArray()];
    }

    public function setBusinessHours(int $branchId, HoursInput $input): array
    {
        $now = $this->now();

        /** @var BusinessHours $hours */
        $hours = $this->unit('hours.set', function () use ($branchId, $input, $now): array {
            $rev = $this->revisions->next();
            $this->requireBranch($branchId);

            $existing = $this->hours->findForBranch($branchId, includeDeleted: true);

            if ($existing === null) {
                $id    = $this->ids->reserve('business_hours');
                $hours = BusinessHours::create($id, $branchId, $input->fields(), $now);

                $hours->stampRev($rev);
                $this->hours->insert($hours);

                return [$hours, true];
            }

            $existing->revise($input->fields(), $now);
            $existing->stampRev($rev);
            $this->hours->save($existing);

            return [$existing, true];
        });

        return $hours->toArray();
    }

    public function staff(StaffQuery $query): array
    {
        return ['items' => $this->presentStaff($this->staff->search($query))];
    }

    public function assignable(StaffQuery $query): array
    {
        // Whatever status the caller asked for, a picker offers only active people.
        return ['items' => $this->presentStaff($this->staff->search(StaffQuery::active($query->branchId, $query->role)))];
    }

    public function staffMember(int $id): array
    {
        return $this->requireStaff($id)->toArray();
    }

    public function createStaff(StaffInput $input): array
    {
        $now = $this->now();

        /** @var StaffMember $member */
        $member = $this->unit('staff.create', function () use ($input, $now): array {
            $rev = $this->revisions->next();

            if ($input->branchId() !== null && $this->branches->find($input->branchId()) === null) {
                throw new ValidationException(['branch_id' => 'No such branch.']);
            }

            $id     = $this->ids->reserve('staff_members');
            $member = StaffMember::create($id, $input->changes(), $now);

            $member->stampRev($rev);
            $this->staff->insert($member);

            return [$member, true];
        });

        return $member->toArray();
    }

    public function updateStaff(int $id, StaffInput $input): array
    {
        $now = $this->now();

        /** @var StaffMember $member */
        $member = $this->unit('staff.update', function () use ($id, $input, $now): array {
            $rev    = $this->revisions->next();
            $member = $this->requireStaff($id);

            if ($input->changes() === []) {
                return [$member, false];
            }

            $member->revise($input->changes(), $now);
            $member->stampRev($rev);
            $this->staff->save($member);

            return [$member, true];
        });

        return $member->toArray();
    }

    public function banStaff(int $id): array
    {
        $now = $this->now();

        /** @var StaffMember $member */
        $member = $this->unit('staff.ban', function () use ($id, $now): array {
            $rev    = $this->revisions->next();
            $member = $this->requireStaff($id);

            if (!$member->ban($now)) {
                return [$member, false];
            }

            $member->stampRev($rev);
            $this->staff->save($member);

            // A banned person cannot hold a manager seat. Clear it, with its own rev.
            $this->clearManagerSeatIfHeldBy($member->id(), $member->branchId(), $now);

            return [$member, true];
        });

        return $member->toArray();
    }

    public function reinstateStaff(int $id): array
    {
        $now = $this->now();

        /** @var StaffMember $member */
        $member = $this->unit('staff.reinstate', function () use ($id, $now): array {
            $rev    = $this->revisions->next();
            $member = $this->requireStaff($id);

            if (!$member->reinstate($now)) {
                return [$member, false];
            }

            // Reinstating does not restore a manager seat. That is a choice a person makes.
            $member->stampRev($rev);
            $this->staff->save($member);

            return [$member, true];
        });

        return $member->toArray();
    }

    public function assignStaffToBranch(int $id, ?int $branchId): array
    {
        $now = $this->now();

        /** @var StaffMember $member */
        $member = $this->unit('staff.assign_branch', function () use ($id, $branchId, $now): array {
            $rev    = $this->revisions->next();
            $member = $this->requireStaff($id);
            $from   = $member->branchId();

            if ($from === $branchId) {
                return [$member, false];
            }

            if ($branchId !== null) {
                if ($this->branches->find($branchId) === null) {
                    throw new ValidationException(['branch_id' => 'No such branch.']);
                }

                if ($member->isBanned()) {
                    throw new ValidationException(['branch_id' => 'A banned member cannot be posted. Reinstate them first.']);
                }
            }

            $member->assignBranch($branchId, $now);
            $member->stampRev($rev);
            $this->staff->save($member);

            // Leaving a branch leaves its manager seat too.
            $this->clearManagerSeatIfHeldBy($member->id(), $from, $now);

            return [$member, true];
        });

        return $member->toArray();
    }

    /**
     * Clears the manager seat at $branchId if $staffId holds it. Takes its own
     * rev, because it is a separate row.
     */
    private function clearManagerSeatIfHeldBy(int $staffId, ?int $branchId, \DateTimeImmutable $now): void
    {
        if ($branchId === null) {
            return;
        }

        $branch = $this->branches->find($branchId);

        if ($branch === null || !$branch->isManager($staffId)) {
            return;
        }

        $branch->clearManager($now);
        $branch->stampRev($this->revisions->next());
        $this->branches->save($branch);
    }

    private function requireBranch(int $id): Branch
    {
        $branch = $this->branches->find($id);

        if ($branch === null) {
            throw new OrganisationNotFoundException(sprintf('Branch [%d] was not found.', $id), layer: 'service.organisation', context: ['id' => $id]);
        }

        return $branch;
    }

    private function requireStaff(int $id): StaffMember
    {
        $member = $this->staff->find($id);

        if ($member === null) {
            throw new OrganisationNotFoundException(sprintf('Staff member [%d] was not found.', $id), layer: 'service.organisation', context: ['id' => $id]);
        }

        return $member;
    }

    /**
     * Runs one unit of work inside a transaction. The work returns the result
     * and whether to commit; false means "nothing changed", and rolls back.
     *
     * @param callable(): array{0: mixed, 1: bool} $work
     */
    private function unit(string $operation, callable $work): mixed
    {
        $this->transaction->begin();

        try {
            [$result, $persist] = $work();

            if ($persist) {
                $this->transaction->commit();
            } else {
                $this->transaction->rollback();
            }

            return $result;
        } catch (ServiceException|ValidationException|\DomainException $e) {
            $this->transaction->rollback();

            throw $e;
        } catch (\Throwable $e) {
            $this->transaction->rollback();

            throw new ServiceException('organisation.' . $operation . '.failed', layer: 'service.organisation', previous: $e);
        }
    }

    /** @param list<StaffMember> $members @return list<array<string, mixed>> */
    private function presentStaff(array $members): array
    {
        return array_map(static fn(StaffMember $m): array => $m->toArray(), $members);
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
