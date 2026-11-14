<?php

declare(strict_types=1);

namespace Plugins\LoanOrganisation\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\LoanOrganisation\Domain\BusinessHours;

/** The `business_hours` table: one schedule per branch. */
final class BusinessHoursRepository
{
    /** Columns this table stores as a BOOLEAN. */
    private const array BOOLEANS = ['close_reminder'];

    public function __construct(
        private readonly DatabasePort $db,
    ) {
    }

    public function findForBranch(int $branchId, bool $includeDeleted = false): ?BusinessHours
    {
        try {
            $row = $this->db->queryOne(
                'SELECT * FROM business_hours WHERE branch_id = :branch'
                . ($includeDeleted ? '' : ' AND deleted_at IS NULL')
                . ' ORDER BY id ASC',
                ['branch' => $branchId],
            );
        } catch (\Throwable $e) {
            throw $this->failed('read', $e);
        }

        return $row === null ? null : BusinessHours::reconstitute($this->normalise($row));
    }

    public function insert(BusinessHours $hours): void
    {
        $row = ['id' => $hours->id(), 'device_id' => null, 'created_at' => $hours->createdAt()] + $hours->toRow();

        $columns = array_keys($row);

        try {
            $this->db->execute(
                sprintf(
                    'INSERT INTO business_hours (%s) VALUES (%s)',
                    implode(', ', $columns),
                    implode(', ', array_map(static fn(string $c): string => ':' . $c, $columns)),
                ),
                $row,
            );
        } catch (\Throwable $e) {
            throw $this->failed('insert', $e);
        }
    }

    public function save(BusinessHours $hours): void
    {
        $row = $hours->toRow();

        $assignments = implode(', ', array_map(
            static fn(string $c): string => sprintf('%s = :%s', $c, $c),
            array_keys($row),
        ));

        try {
            $this->db->execute(
                sprintf('UPDATE business_hours SET %s WHERE id = :where_id', $assignments),
                $row + ['where_id' => $hours->id()],
            );
        } catch (\Throwable $e) {
            throw $this->failed('update', $e);
        }
    }

    private function failed(string $verb, \Throwable $previous): RepositoryException
    {
        return new RepositoryException(
            sprintf('Failed to %s business hours.', $verb),
            layer: 'repository.organisation.hours',
            previous: $previous,
        );
    }

    /**
     * Coerce this table's BOOLEAN columns to 1/0 before the Domain sees them.
     *
     * The Domain may not know what a driver is, and the four the kernel
     * supports hand a boolean back four ways — PostgreSQL's arrives as a PHP
     * bool, or as the string 't' when fetches are stringified, and `(bool) 'f'`
     * is true. Normalising here keeps `BusinessHours::reconstitute()` reading one
     * shape on every database.
     *
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalise(array $row): array
    {
        foreach (self::BOOLEANS as $column) {
            if (array_key_exists($column, $row)) {
                $row[$column] = SqlDialect::boolToWire($row[$column]);
            }
        }

        return $row;
    }
}
