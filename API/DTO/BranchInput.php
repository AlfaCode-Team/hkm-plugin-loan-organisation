<?php

declare(strict_types=1);

namespace Plugins\LoanOrganisation\API\DTO;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use Plugins\LoanOrganisation\Domain\BranchStatus;
use Plugins\LoanOrganisation\Domain\OpeningAmount;

/**
 * A branch write, parsed into typed values. Only keys PRESENT in the request
 * appear in {@see self::changes()}: an update that omits `code` leaves it alone,
 * and one that sends `code: null` clears it.
 *
 * `manager_staff_id` is deliberately not accepted here. The manager is set by
 * its own endpoint, because that is the call that checks the person is posted
 * to this branch.
 */
final readonly class BranchInput
{
    /** input key => max length */
    private const array TEXT = [
        'name'     => 160,
        'code'     => 32,
        'district' => 120,
        'address'  => 255,
        'phone'    => 32,
    ];

    /** @param array<string, mixed> $changes */
    private function __construct(private array $changes)
    {
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
            $errors['name'] = 'A branch name is required.';
        }

        self::collect($errors, 'opened_on', $data, $changes, static fn(mixed $v): ?string => InputText::date($v));

        if (array_key_exists('status', $data)) {
            $status = is_string($data['status']) ? BranchStatus::tryFrom(strtolower(trim($data['status']))) : null;

            if ($status === null) {
                $errors['status'] = 'Status must be open or closed.';
            } else {
                $changes['status'] = $status;
            }
        }

        foreach (['opening_cash', 'opening_float'] as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }

            try {
                // Null is a real instruction here: "not counted" again.
                $changes[$key] = OpeningAmount::from($data[$key], $key);
            } catch (\DomainException $e) {
                $errors[$key] = $e->getMessage();
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new self($changes);
    }

    /** @return array<string, mixed> */
    public function changes(): array
    {
        return $this->changes;
    }

    /**
     * @param array<string, string>        $errors
     * @param array<string, mixed>         $data
     * @param array<string, mixed>         $changes
     * @param callable(mixed): mixed       $parse
     */
    private static function collect(array &$errors, string $key, array $data, array &$changes, callable $parse): void
    {
        if (!array_key_exists($key, $data)) {
            return;
        }

        try {
            $changes[$key] = $parse($data[$key]);
        } catch (\DomainException $e) {
            $errors[$key] = $e->getMessage();
        }
    }
}
