<?php

declare(strict_types=1);

namespace Plugins\LoanOrganisation\API\Exceptions;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\HttpStatusAware;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;

/**
 * No live branch or staff member has that id. A tombstoned row is reported the
 * same way: to a caller a deleted row no longer exists.
 */
final class OrganisationNotFoundException extends ServiceException implements HttpStatusAware
{
    public function httpStatus(): int
    {
        return 404;
    }
}
