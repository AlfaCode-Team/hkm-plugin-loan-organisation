<?php

declare(strict_types=1);

namespace Plugins\LoanOrganisation\API\DTO;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use Plugins\LoanOrganisation\Domain\BusinessHours;

/**
 * A full business-hours schedule for one branch. The whole week is sent every
 * time; {@see BusinessHours::normaliseDays()} does the day-by-day checks.
 */
final readonly class HoursInput
{
    /** @param array<string, mixed> $fields */
    private function __construct(private array $fields)
    {
    }

    /**
     * @param array<string, mixed> $data
     * @throws ValidationException
     */
    public static function fromArray(array $data): self
    {
        $errors = [];
        $fields = [];

        try {
            $fields['days'] = BusinessHours::normaliseDays($data['days'] ?? null);
        } catch (\DomainException $e) {
            $errors['days'] = $e->getMessage();
        }

        foreach (['opening_time', 'closing_time'] as $key) {
            try {
                $fields[$key] = InputText::clock($data[$key] ?? null);
            } catch (\DomainException $e) {
                $errors[$key] = $e->getMessage();
            }
        }

        try {
            $fields['close_reminder'] = InputText::bool($data['close_reminder'] ?? null, default: true);
        } catch (\DomainException $e) {
            $errors['close_reminder'] = $e->getMessage();
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new self($fields);
    }

    /** @return array<string, mixed> */
    public function fields(): array
    {
        return $this->fields;
    }
}
