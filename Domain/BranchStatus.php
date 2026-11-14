<?php

declare(strict_types=1);

namespace Plugins\LoanOrganisation\Domain;

enum BranchStatus: string
{
    case Open   = 'open';
    case Closed = 'closed';
}
