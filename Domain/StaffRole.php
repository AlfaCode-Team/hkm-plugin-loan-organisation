<?php

declare(strict_types=1);

namespace Plugins\LoanOrganisation\Domain;

/** What a person does at a branch. A person holds exactly one role. */
enum StaffRole: string
{
    case Officer = 'officer';
    case Cashier = 'cashier';
}
