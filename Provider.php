<?php

declare(strict_types=1);

namespace Plugins\LoanOrganisation;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\LoanOrganisation\API\Contracts\OrganisationServiceContract;
use Plugins\LoanOrganisation\API\Contracts\StaffDirectoryContract;
use Plugins\LoanOrganisation\Application\Services\OrganisationService;
use Plugins\LoanOrganisation\Infrastructure\Http\Controllers\BranchController;
use Plugins\LoanOrganisation\Infrastructure\Http\Controllers\StaffController;
use Plugins\LoanOrganisation\Infrastructure\Persistence\BranchRepository;
use Plugins\LoanOrganisation\Infrastructure\Persistence\BusinessHoursRepository;
use Plugins\LoanOrganisation\Infrastructure\Persistence\IdAllocator;
use Plugins\LoanOrganisation\Infrastructure\Persistence\StaffDirectory;
use Plugins\LoanOrganisation\Infrastructure\Persistence\StaffRepository;
use Plugins\LoanSync\API\Contracts\SyncRevisionContract;

/**
 * Organisation — owns 'organisation.structure'.
 *
 * Every binding is resolved per request, so the DatabasePort it receives is the
 * request's own tenant database, never the central one.
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'organisation.structure';
    }

    /** @return list<string> */
    public function requires(): array
    {
        return ['database.management', 'sync.offline'];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [OrganisationServiceContract::class, StaffDirectoryContract::class];
    }

    public function register(ModuleContainer $container): void
    {
        // PUBLIC: other modules resolve `recorded_by` through this rather
        // than assuming a user id and a staff id are the same number.
        $container->bind(
            StaffDirectoryContract::class,
            static fn($c): StaffDirectory => new StaffDirectory($c->make(DatabasePort::class)),
        );

        $container->bindInternal(
            IdAllocator::class,
            static fn($c): IdAllocator => new IdAllocator($c->make(DatabasePort::class)),
        );

        $container->bindInternal(
            BranchRepository::class,
            static fn($c): BranchRepository => new BranchRepository($c->make(DatabasePort::class)),
        );

        $container->bindInternal(
            StaffRepository::class,
            static fn($c): StaffRepository => new StaffRepository($c->make(DatabasePort::class)),
        );

        $container->bindInternal(
            BusinessHoursRepository::class,
            static fn($c): BusinessHoursRepository => new BusinessHoursRepository($c->make(DatabasePort::class)),
        );

        $container->bind(
            OrganisationServiceContract::class,
            static fn($c): OrganisationService => new OrganisationService(
                branches:    $c->make(BranchRepository::class),
                staff:       $c->make(StaffRepository::class),
                hours:       $c->make(BusinessHoursRepository::class),
                ids:         $c->make(IdAllocator::class),
                revisions:   $c->make(SyncRevisionContract::class),
                transaction: $c->make(TransactionManager::class),
            ),
        );

        $container->bind(
            BranchController::class,
            static fn($c): BranchController => new BranchController($c->make(OrganisationServiceContract::class)),
        );

        $container->bind(
            StaffController::class,
            static fn($c): StaffController => new StaffController($c->make(OrganisationServiceContract::class)),
        );
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // Routes are declared in module.json; nothing to hook.
    }
}
