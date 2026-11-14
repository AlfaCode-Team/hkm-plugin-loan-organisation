<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\LoanOrganisation\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\LoanOrganisation\API\DTO\BranchInput;
use Plugins\LoanOrganisation\API\DTO\HoursInput;
use Plugins\LoanOrganisation\API\DTO\StaffInput;
use Plugins\LoanOrganisation\Application\Services\OrganisationService;
use Plugins\LoanOrganisation\Infrastructure\Persistence\BranchRepository;
use Plugins\LoanOrganisation\Infrastructure\Persistence\BusinessHoursRepository;
use Plugins\LoanOrganisation\Infrastructure\Persistence\IdAllocator;
use Plugins\LoanOrganisation\Infrastructure\Persistence\StaffRepository;
use Plugins\LoanSync\Application\Services\SyncService;
use Plugins\LoanSync\Infrastructure\Persistence\DeviceRepository;
use Plugins\LoanSync\Infrastructure\Persistence\EntityRepository;
use Plugins\LoanSync\Infrastructure\Persistence\OperationLog;
use Plugins\LoanSync\Infrastructure\Persistence\RevisionRepository;
use Plugins\LoanSync\Infrastructure\Persistence\ShardRepository;
use Tests\Unit\Plugins\LoanOrganisation\Support\TestDatabase;

/**
 * A real tenant database on disk, migrated by the real migration runner, with the
 * real adapter, the real Organisation service and the real Sync revision counter
 * in front of it. Nothing here is a fake DatabasePort.
 */
final class OrganisationHarness
{
    public readonly DatabasePort $db;
    public readonly OrganisationService $org;
    public readonly SyncService $sync;
    public readonly RevisionRepository $revisions;
    public readonly TestDatabase $database;

    public function __construct()
    {
        // SQLite unless HKM_TEST_DB says otherwise. The same tests then run
        // against MySQL, PostgreSQL or SQL Server without a second suite.
        //
        // The schema is assembled from the PLUGINS that own each table, so
        // this suite tests against the same migrations a deployment runs.
        $this->database = TestDatabase::provision('loan_organisation', [
            \Plugins\LoanSync\Provider::class,
            \Plugins\LoanOrganisation\Provider::class,
        ]);
        $this->db        = $this->database->port;
        $this->revisions = new RevisionRepository($this->db);

        $this->org = new OrganisationService(
            branches:    new BranchRepository($this->db),
            staff:       new StaffRepository($this->db),
            hours:       new BusinessHoursRepository($this->db),
            ids:         new IdAllocator($this->db),
            revisions:   $this->revisions,
            transaction: new TransactionManager($this->db),
        );

        $this->sync = new SyncService(
            entities:    new EntityRepository($this->db),
            revisions:   $this->revisions,
            operations:  new OperationLog($this->db),
            shards:      new ShardRepository($this->db),
            devices:     new DeviceRepository($this->db),
            transaction: new TransactionManager($this->db),
            identity:    new Identity(
                userId:      'manager-1',
                tenantId:    'tenant-test',
                roles:       ['admin'],
                permissions: [],
                tokenType:   'jwt',
            ),
        );
    }

    /** @param array<string, mixed> $data */
    public static function branchInput(array $data = ['name' => 'Kampala Central']): BranchInput
    {
        return BranchInput::fromArray($data, creating: true);
    }

    /** @param array<string, mixed> $data */
    public static function staffInput(array $data): StaffInput
    {
        return StaffInput::fromArray($data, creating: true);
    }

    /** A full week, Sunday closed. Overrides replace the whole week when given. */
    public static function week(): HoursInput
    {
        $days = [];

        foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $day) {
            $days[] = ['day' => $day, 'open' => '08:00', 'close' => '18:00', 'closed' => $day === 'sun'];
        }

        return HoursInput::fromArray(['days' => $days, 'opening_time' => '08:00', 'closing_time' => '18:00']);
    }

    /** The newest rev the tenant has issued. */
    public function currentRev(): int
    {
        return $this->revisions->current();
    }

    /** The stored rev of one row, read straight from the table. */
    public function rowRev(string $table, int $id): int
    {
        return (int) $this->db->queryOne(sprintf('SELECT rev FROM %s WHERE id = :id', $table), ['id' => $id])['rev'];
    }

    public function destroy(): void
    {
        $this->database->destroy();
    }
}
