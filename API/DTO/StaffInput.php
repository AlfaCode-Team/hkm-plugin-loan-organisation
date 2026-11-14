<?php

declare(strict_types=1);

namespace Plugins\LoanOrganisation\API\DTO;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use Plugins\LoanOrganisation\Domain\StaffRole;

/**
 * A staff write, parsed into typed values.
 *
 * `branch_id` is accepted ONLY on create (hiring someone into a branch). On an
 * update it is ignored, because a change of posting is a different act with its
 * own rules (the manager is cleared, a banned person cannot be posted) and has
 * its own endpoint. `banned` is never accepted here at all: it has ban and
 * reinstate.
 */
final readonly class StaffInput
{
    /** input key => max length */
    private const array TEXT = [
        'name'       => 160,
        'phone'      => 32,
        'region'     => 120,
        'photo_path' => 255,
    ];

    /**
     * @param array<string, mixed> $changes
     * @param ?int                 $branchId only meaningful on create
     */
    private function __construct(
        private array $changes,
        private ?int $branchId,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @throws ValidationException
     */
    public static function fromArray(array $data, bool $creating): self
    {
        $changes = [];
        $errors  = [];

        foreach (self::TEXT as $key => $max) {
            if (!array_key_exists($key, $data)) {
                continue;
            }

            try {
                $changes[$key] = InputText::text($data[$key], $max);
            } catch (\DomainException $e) {
                $errors[$key] = $e->getMessage();
            }
        }

        if (($creating || array_key_exists('name', $data)) && !isset($changes['name']) && !isset($errors['name'])) {
            $errors['name'] = 'A name is required.';
        }

        if (array_key_exists('email', $data)) {
            try {
                $changes['email'] = InputText::email($data['email']);
            } catch (\DomainException $e) {
                $errors['email'] = $e->getMessage();
            }
        }

        if (array_key_exists('hired_on', $data)) {
            try {
                $changes['hired_on'] = InputText::date($data['hired_on']);
            } catch (\DomainException $e) {
                $errors['hired_on'] = $e->getMessage();
            }
        }

        if ($creating || array_key_exists('role', $data)) {
            $role = is_string($data['role'] ?? null) ? StaffRole::tryFrom(strtolower(trim($data['role']))) : null;

            if ($role === null) {
                $errors['role'] = 'Role must be officer or cashier.';
            } else {
                $changes['role'] = $role;
            }
        }

        $branchId = null;

        if ($creating && array_key_exists('branch_id', $data)) {
            try {
                $branchId = InputText::positiveId($data['branch_id'], 'branch_id');
            } catch (ValidationException $e) {
                $errors['branch_id'] = $e->errors['branch_id'];
            }
        }

        if (array_key_exists('user_id', $data)) {
            try {
                $changes['user_id'] = InputText::userId($data['user_id'], 'user_id');
            } catch (ValidationException $e) {
                $errors['user_id'] = $e->errors['user_id'];
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        if ($creating) {
            $changes['branch_id'] = $branchId;
        }

        return new self($changes, $branchId);
    }

    /** @return array<string, mixed> */
    public function changes(): array
    {
        return $this->changes;
    }

    public function branchId(): ?int
    {
        return $this->branchId;
    }
}
