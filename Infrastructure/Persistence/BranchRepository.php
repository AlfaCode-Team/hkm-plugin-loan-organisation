<?php

declare(strict_types=1);

namespace Plugins\LoanOrganisation\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\LoanOrganisation\Domain\Branch;

/** The `branches` table, and the only place that knows its columns. */
final class BranchRepository
{
    public function __construct(
        private readonly DatabasePort $db,
    ) {
    }

    public function find(int $id, bool $includeDeleted = false): ?Branch
    {
        try {
            $row = $this->db->queryOne(
                'SELECT * FROM branches WHERE id = :id' . ($includeDeleted ? '' : ' AND deleted_at IS NULL'),
                ['id' => $id],
            );
        } catch (\Throwable $e) {
            throw $this->failed('read', $e);
        }

        return $row === null ? null : Branch::reconstitute($row);
    }

    /** @return list<Branch> */
    public function all(bool $includeClosed): array
    {
        try {
            $rows = $this->db->query(
                'SELECT * FROM branches WHERE deleted_at IS NULL'
                . ($includeClosed ? '' : " AND status = 'open'")
                . ' ORDER BY name ASC, id ASC',
            );
        } catch (\Throwable $e) {
            throw $this->failed('list', $e);
        }

        return array_map(static fn(array $row): Branch => Branch::reconstitute($row), $rows);
    }

    public function insert(Branch $branch): void
    {
        $row = ['id' => $branch->id(), 'device_id' => null, 'created_at' => $branch->createdAt()] + $branch->toRow();

        $columns = array_keys($row);

        try {
            $this->db->execute(
                sprintf(
                    'INSERT INTO branches (%s) VALUES (%s)',
                    implode(', ', $columns),
                    implode(', ', array_map(static fn(string $c): string => ':' . $c, $columns)),
                ),
                $row,
            );
        } catch (\Throwable $e) {
            throw $this->failed('insert', $e);
        }
    }

    /**
     * Writes the mutable columns. `id`, `device_id` and `created_at` are left
     * alone: they describe how the row was made, and an edit does not change that.
     */
    public function save(Branch $branch): void
    {
        $row = $branch->toRow();

        $assignments = implode(', ', array_map(
            static fn(string $c): string => sprintf('%s = :%s', $c, $c),
            array_keys($row),
        ));

        try {
            $this->db->execute(
                sprintf('UPDATE branches SET %s WHERE id = :where_id', $assignments),
                $row + ['where_id' => $branch->id()],
            );
        } catch (\Throwable $e) {
            throw $this->failed('update', $e);
        }
    }

    private function failed(string $verb, \Throwable $previous): RepositoryException
    {
        return new RepositoryException(
            sprintf('Failed to %s a branch.', $verb),
            layer: 'repository.organisation.branches',
            previous: $previous,
        );
    }
}
