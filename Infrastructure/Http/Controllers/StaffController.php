<?php

declare(strict_types=1);

namespace Plugins\LoanOrganisation\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\LoanOrganisation\API\Contracts\OrganisationServiceContract;
use Plugins\LoanOrganisation\API\DTO\InputText;
use Plugins\LoanOrganisation\API\DTO\StaffInput;
use Plugins\LoanOrganisation\API\DTO\StaffQuery;

/**
 * Translation only. The assignable list and the status-filtered listing are two
 * separate routes on purpose: a picker must never be able to show a banned person.
 */
final class StaffController
{
    public function __construct(
        private readonly OrganisationServiceContract $organisation,
    ) {
    }

    public function index(Request $request): Response
    {
        return Response::json($this->organisation->staff(StaffQuery::fromQuery($request->queryAll())));
    }

    public function assignable(Request $request): Response
    {
        return Response::json($this->organisation->assignable(StaffQuery::fromQuery($request->queryAll())));
    }

    public function show(Request $request, string $id): Response
    {
        return Response::json($this->organisation->staffMember((int) $id));
    }

    public function store(Request $request): Response
    {
        return Response::json($this->organisation->createStaff(StaffInput::fromArray($request->all(), creating: true)), 201);
    }

    public function update(Request $request, string $id): Response
    {
        return Response::json($this->organisation->updateStaff((int) $id, StaffInput::fromArray($request->all(), creating: false)));
    }

    public function ban(Request $request, string $id): Response
    {
        return Response::json($this->organisation->banStaff((int) $id));
    }

    public function reinstate(Request $request, string $id): Response
    {
        return Response::json($this->organisation->reinstateStaff((int) $id));
    }

    public function assignBranch(Request $request, string $id): Response
    {
        return Response::json($this->organisation->assignStaffToBranch((int) $id, InputText::positiveId($request->input('branch_id'), 'branch_id')));
    }
}
