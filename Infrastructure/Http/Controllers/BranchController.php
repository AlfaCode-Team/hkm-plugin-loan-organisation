<?php

declare(strict_types=1);

namespace Plugins\LoanOrganisation\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\LoanOrganisation\API\Contracts\OrganisationServiceContract;
use Plugins\LoanOrganisation\API\DTO\BranchInput;
use Plugins\LoanOrganisation\API\DTO\HoursInput;
use Plugins\LoanOrganisation\API\DTO\InputText;

/**
 * Translation only: parse the request, call the service, render the result.
 */
final class BranchController
{
    public function __construct(
        private readonly OrganisationServiceContract $organisation,
    ) {
    }

    public function index(Request $request): Response
    {
        return Response::json($this->organisation->branches($request->boolean('include_closed', true)));
    }

    public function show(Request $request, string $id): Response
    {
        return Response::json($this->organisation->branch((int) $id));
    }

    public function store(Request $request): Response
    {
        return Response::json($this->organisation->createBranch(BranchInput::fromArray($request->all(), creating: true)), 201);
    }

    public function update(Request $request, string $id): Response
    {
        return Response::json($this->organisation->updateBranch((int) $id, BranchInput::fromArray($request->all(), creating: false)));
    }

    public function assignManager(Request $request, string $id): Response
    {
        return Response::json($this->organisation->assignManager((int) $id, InputText::positiveId($request->input('staff_id'), 'staff_id')));
    }

    public function showHours(Request $request, string $id): Response
    {
        return Response::json($this->organisation->businessHours((int) $id));
    }

    public function setHours(Request $request, string $id): Response
    {
        return Response::json($this->organisation->setBusinessHours((int) $id, HoursInput::fromArray($request->all())));
    }
}
