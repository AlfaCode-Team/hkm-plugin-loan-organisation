<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\LoanOrganisation;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\LoanOrganisation\API\DTO\BranchInput;
use Plugins\LoanOrganisation\API\DTO\StaffInput;
use Plugins\LoanOrganisation\API\DTO\StaffQuery;
use Plugins\LoanOrganisation\API\Exceptions\OrganisationNotFoundException;
use Plugins\LoanOrganisation\Application\Services\OrganisationService;
use Tests\Unit\Plugins\LoanOrganisation\Support\OrganisationHarness;

#[CoversClass(OrganisationService::class)]
final class OrganisationServiceTest extends TestCase
{
    private ?OrganisationHarness $harness = null;

    protected function tearDown(): void
    {
        // Positively identified: the one file this test created.
        $this->harness?->destroy();
        $this->harness = null;
    }

    private function harness(): OrganisationHarness
    {
        return $this->harness ??= new OrganisationHarness();
    }

    /** @param array<string, mixed> $data */
    private function branch(OrganisationHarness $h, array $data = ['name' => 'Kampala Central']): array
    {
        return $h->org->createBranch(OrganisationHarness::branchInput($data));
    }

    /** @param array<string, mixed> $data */
    private function staff(OrganisationHarness $h, array $data): array
    {
        return $h->org->createStaff(OrganisationHarness::staffInput($data));
    }

    public function test_a_branch_is_created_without_a_manager_and_takes_one_afterwards(): void
    {
        $h = $this->harness();

        $branch = $this->branch($h);

        self::assertNull($branch['manager_staff_id'], 'a branch can be created before anyone is posted to it');

        $officer = $this->staff($h, ['name' => 'Aminah Nakato', 'role' => 'officer', 'branch_id' => $branch['id']]);

        $managed = $h->org->assignManager($branch['id'], $officer['id']);

        self::assertSame($officer['id'], $managed['manager_staff_id']);
        self::assertGreaterThan($branch['rev'], $managed['rev'], 'assigning a manager is a write and takes a rev');
        self::assertSame($officer['id'], $h->org->branch($branch['id'])['manager_staff_id'], 'the manager persists');

        $cleared = $h->org->assignManager($branch['id'], null);

        self::assertNull($cleared['manager_staff_id'], 'the seat can be emptied again');
    }

    public function test_a_manager_must_be_an_active_member_posted_to_that_branch(): void
    {
        $h = $this->harness();

        $here  = $this->branch($h, ['name' => 'Here']);
        $there = $this->branch($h, ['name' => 'There']);
        $away  = $this->staff($h, ['name' => 'Okello', 'role' => 'cashier', 'branch_id' => $there['id']]);

        $this->expectException(ValidationException::class);

        $h->org->assignManager($here['id'], $away['id']);
    }

    public function test_opening_cash_stays_null_until_counted_and_a_counted_zero_is_stored_as_zero(): void
    {
        $h = $this->harness();

        $branch = $this->branch($h);

        self::assertNull($branch['opening_cash'], 'never counted');
        self::assertNull($branch['money_set_at'], 'the one-time prompt is still owed');

        $counted = $h->org->updateBranch($branch['id'], BranchInput::fromArray(['opening_cash' => '0', 'opening_float' => 0], creating: false));

        self::assertSame(0, $counted['opening_cash'], 'a counted zero is an answer, not an absence');
        self::assertSame(0, $counted['opening_float']);
        self::assertNotNull($counted['money_set_at'], 'counting, even to zero, sets the moment');

        // The two facts are distinguishable in the table itself, not only in PHP.
        $unset = $h->db->queryOne('SELECT COUNT(*) AS n FROM branches WHERE id = :id AND opening_cash IS NULL', ['id' => $branch['id']]);
        $zero  = $h->db->queryOne('SELECT COUNT(*) AS n FROM branches WHERE id = :id AND opening_cash = 0', ['id' => $branch['id']]);

        self::assertSame(0, (int) $unset['n'], 'a counted zero is not NULL in the database');
        self::assertSame(1, (int) $zero['n']);

        $other = $this->branch($h, ['name' => 'Entebbe']);
        $never = $h->db->queryOne('SELECT COUNT(*) AS n FROM branches WHERE id = :id AND opening_cash IS NULL', ['id' => $other['id']]);

        self::assertSame(1, (int) $never['n'], 'a branch never counted is NULL, not 0');
    }

    public function test_a_counted_figure_is_left_alone_when_omitted_and_cleared_only_by_an_explicit_null(): void
    {
        $h      = $this->harness();
        $branch = $this->branch($h, ['name' => 'Jinja', 'opening_cash' => 500000]);

        self::assertSame(500000, $branch['opening_cash']);

        $renamed = $h->org->updateBranch($branch['id'], BranchInput::fromArray(['district' => 'Jinja'], creating: false));

        self::assertSame(500000, $renamed['opening_cash'], 'an update that omits the figure leaves it alone');

        $reset = $h->org->updateBranch($branch['id'], BranchInput::fromArray(['opening_cash' => null], creating: false));

        self::assertNull($reset['opening_cash'], 'an explicit null means not counted again');
    }

    public function test_money_that_is_not_whole_shillings_is_refused(): void
    {
        $h      = $this->harness();
        $branch = $this->branch($h);

        $this->expectException(ValidationException::class);

        $h->org->updateBranch($branch['id'], BranchInput::fromArray(['opening_float' => 1500.5], creating: false));
    }

    public function test_banning_removes_someone_from_the_assignable_list_but_not_from_a_historical_lookup(): void
    {
        $h      = $this->harness();
        $branch = $this->branch($h);
        $gone   = $this->staff($h, ['name' => 'Aminah Nakato', 'role' => 'officer', 'branch_id' => $branch['id']]);
        $stays  = $this->staff($h, ['name' => 'Okello Tom', 'role' => 'cashier', 'branch_id' => $branch['id']]);

        $query = StaffQuery::fromQuery(['branch_id' => (string) $branch['id']]);

        self::assertSame([$gone['id'], $stays['id']], array_column($h->org->assignable($query)['items'], 'id'));

        $h->org->banStaff($gone['id']);

        self::assertSame([$stays['id']], array_column($h->org->assignable($query)['items'], 'id'), 'a banned person is not offered for assignment');
        self::assertSame([$stays['id']], array_column($h->org->staff(StaffQuery::fromQuery(['status' => 'active']))['items'], 'id'));
        self::assertSame([$gone['id']], array_column($h->org->staff(StaffQuery::fromQuery(['status' => 'banned']))['items'], 'id'));

        // The historical lookup still resolves them: their name is on receipts already printed.
        $record = $h->org->staffMember($gone['id']);

        self::assertSame('Aminah Nakato', $record['name']);
        self::assertTrue($record['banned']);
        self::assertFalse($record['assignable']);
        self::assertSame($branch['id'], $record['branch_id'], 'the posting is kept as history');
    }

    public function test_a_reinstated_person_is_offered_again(): void
    {
        $h      = $this->harness();
        $branch = $this->branch($h);
        $person = $this->staff($h, ['name' => 'Nabirye', 'role' => 'officer', 'branch_id' => $branch['id']]);

        $h->org->banStaff($person['id']);
        $h->org->reinstateStaff($person['id']);

        self::assertSame([$person['id']], array_column($h->org->assignable(StaffQuery::fromQuery([]))['items'], 'id'));
    }

    public function test_banning_a_manager_clears_the_seat_and_takes_a_rev_on_the_branch(): void
    {
        $h      = $this->harness();
        $branch = $this->branch($h);
        $boss   = $this->staff($h, ['name' => 'Kato', 'role' => 'officer', 'branch_id' => $branch['id']]);

        $h->org->assignManager($branch['id'], $boss['id']);
        $h->org->banStaff($boss['id']);

        $after = $h->org->branch($branch['id']);

        self::assertNull($after['manager_staff_id'], 'a banned person does not keep a manager seat');
        self::assertSame($h->currentRev(), $h->rowRev('branches', $branch['id']), 'the branch change is pullable');
    }

    public function test_transferring_a_manager_out_clears_the_seat_they_left(): void
    {
        $h    = $this->harness();
        $one  = $this->branch($h, ['name' => 'One']);
        $two  = $this->branch($h, ['name' => 'Two']);
        $boss = $this->staff($h, ['name' => 'Namara', 'role' => 'officer', 'branch_id' => $one['id']]);

        $h->org->assignManager($one['id'], $boss['id']);
        $h->org->assignStaffToBranch($boss['id'], $two['id']);

        self::assertNull($h->org->branch($one['id'])['manager_staff_id'], 'a manager transferred out clears the old seat');
        self::assertSame($two['id'], $h->org->staffMember($boss['id'])['branch_id']);
    }

    public function test_a_banned_person_cannot_be_posted_or_made_manager(): void
    {
        $h      = $this->harness();
        $branch = $this->branch($h);
        $person = $this->staff($h, ['name' => 'Akello', 'role' => 'cashier']);

        $h->org->banStaff($person['id']);

        try {
            $h->org->assignStaffToBranch($person['id'], $branch['id']);
            self::fail('a banned person must not be posted');
        } catch (ValidationException) {
        }

        $this->expectException(ValidationException::class);

        $h->org->assignManager($branch['id'], $person['id']);
    }

    /**
     * Every write takes a rev, and the row it stamps is pullable by a device
     * whose cursor sits just before it.
     */
    public function test_every_write_takes_a_rev_and_reaches_a_handset(): void
    {
        $h = $this->harness();

        $before = $h->currentRev();
        $branch = $this->branch($h);
        $this->assertPullable($h, 'branches', $branch['id'], $before, 'create branch');

        $before = $h->currentRev();
        $h->org->updateBranch($branch['id'], BranchInput::fromArray(['district' => 'Makindye'], creating: false));
        $this->assertPullable($h, 'branches', $branch['id'], $before, 'update branch');

        $before = $h->currentRev();
        $person = $this->staff($h, ['name' => 'Nanteza', 'role' => 'officer', 'branch_id' => $branch['id']]);
        $this->assertPullable($h, 'staff_members', $person['id'], $before, 'create staff');

        $before = $h->currentRev();
        $h->org->updateStaff($person['id'], StaffInput::fromArray(['phone' => '0772000000'], creating: false));
        $this->assertPullable($h, 'staff_members', $person['id'], $before, 'update staff');

        $before = $h->currentRev();
        $h->org->assignManager($branch['id'], $person['id']);
        $this->assertPullable($h, 'branches', $branch['id'], $before, 'assign manager');

        $before = $h->currentRev();
        $h->org->banStaff($person['id']);
        $this->assertPullable($h, 'staff_members', $person['id'], $before, 'ban staff');

        $before = $h->currentRev();
        $h->org->reinstateStaff($person['id']);
        $this->assertPullable($h, 'staff_members', $person['id'], $before, 'reinstate staff');

        $before = $h->currentRev();
        $other = $this->branch($h, ['name' => 'Other']);
        $h->org->assignStaffToBranch($person['id'], $other['id']);
        $this->assertPullable($h, 'staff_members', $person['id'], $before, 'assign to branch');

        $before = $h->currentRev();
        $h->org->setBusinessHours($branch['id'], OrganisationHarness::week());
        $hoursId = (int) $h->org->businessHours($branch['id'])['hours']['id'];
        $this->assertPullable($h, 'business_hours', $hoursId, $before, 'set hours');
    }

    public function test_a_write_that_changes_nothing_takes_no_rev(): void
    {
        $h      = $this->harness();
        $branch = $this->branch($h);
        $person = $this->staff($h, ['name' => 'Ssali', 'role' => 'officer', 'branch_id' => $branch['id']]);

        $rev = $h->currentRev();

        $h->org->updateBranch($branch['id'], BranchInput::fromArray([], creating: false));
        $h->org->reinstateStaff($person['id']);
        $h->org->assignStaffToBranch($person['id'], $branch['id']);
        $h->org->assignManager($branch['id'], null);

        self::assertSame($rev, $h->currentRev(), 'no-op writes must not burn a rev no handset will ever see');
    }

    public function test_a_failed_write_rolls_back_and_takes_no_rev(): void
    {
        $h      = $this->harness();
        $person = $this->staff($h, ['name' => 'Mugisha', 'role' => 'cashier']);

        $rev = $h->currentRev();

        try {
            $h->org->assignStaffToBranch($person['id'], 999999);
            self::fail('an unknown branch must be refused');
        } catch (ValidationException) {
        }

        try {
            $h->org->assignManager(888888, null);
            self::fail('an unknown branch must be reported');
        } catch (OrganisationNotFoundException) {
        }

        self::assertSame($rev, $h->currentRev(), 'a refused write returns the counter');
    }

    public function test_web_created_ids_stay_in_the_server_shard_and_are_never_reused(): void
    {
        $h = $this->harness();

        $first  = $this->branch($h, ['name' => 'First']);
        $second = $this->branch($h, ['name' => 'Second']);

        // The server owns shard 999_999. Shard 0 is the legacy/import range and
        // shards 1..999_998 belong to handsets, so none of the three can ever
        // mint an id another one has already used.
        self::assertGreaterThanOrEqual(999_999_000_000, $first['id'], 'never the legacy range');
        self::assertLessThanOrEqual(999_999_999_999, $first['id'], 'never a handset shard');
        self::assertSame($first['id'] + 1, $second['id'], 'ids are the maximum plus one');
    }

    public function test_business_hours_are_one_schedule_per_branch_and_a_second_set_updates_it(): void
    {
        $h      = $this->harness();
        $branch = $this->branch($h);

        self::assertNull($h->org->businessHours($branch['id'])['hours'], 'hours are null until set');

        $first = $h->org->setBusinessHours($branch['id'], OrganisationHarness::week());

        self::assertCount(7, $first['days']);
        self::assertSame('mon', $first['days'][0]['day']);
        self::assertTrue($first['days'][6]['closed']);
        self::assertTrue($first['close_reminder'], 'the reminder is on unless turned off');

        $second = $h->org->setBusinessHours($branch['id'], OrganisationHarness::week());

        self::assertSame($first['id'], $second['id'], 'one row per branch, updated in place');
        self::assertGreaterThan($first['rev'], $second['rev']);
        self::assertSame(1, (int) $h->db->queryOne('SELECT COUNT(*) AS n FROM business_hours WHERE branch_id = :b', ['b' => $branch['id']])['n']);
    }

    public function test_a_blank_branch_name_is_refused(): void
    {
        $h = $this->harness();

        $this->expectException(ValidationException::class);

        $h->org->createBranch(BranchInput::fromArray(['name' => '   '], creating: true));
    }

    /**
     * The row was stamped with a rev above the cursor (so it is a change), no
     * newer than the tenant's counter, and a device at $cursor receives it.
     *
     * Not an equality with the counter: a ban that clears a manager seat writes
     * a second row with a later rev, and the person's own row is correctly one behind.
     */
    private function assertPullable(OrganisationHarness $h, string $table, int $id, int $cursor, string $what): void
    {
        $rowRev = $h->rowRev($table, $id);

        self::assertGreaterThan($cursor, $rowRev, "$what must take a new rev");
        self::assertLessThanOrEqual($h->currentRev(), $rowRev, "$what must not carry a rev the counter never issued");

        $page = $h->sync->pull('device-a', $cursor, [$table]);
        $ids  = array_map(static fn(array $r): int => (int) $r['id'], $page->changes[$table] ?? []);

        self::assertContains($id, $ids, "$what must reach a handset pulling from before it");
    }
}
