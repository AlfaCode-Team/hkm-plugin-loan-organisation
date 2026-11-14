<?php

declare(strict_types=1);

namespace Plugins\LoanOrganisation\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\LoanOrganisation\API\DTO\StaffQuery;
use Plugins\LoanOrganisation\Domain\StaffMember;

/** The `staff_members` table, and the only place that knows its columns. */
final class StaffRepository
{
    /** Columns this table stores as a BOOLEAN. */
    private const array BOOLEANS = ['banned'];

    public function __construct(
        private readonly DatabasePort $db,
    ) {
    }

    public function find(int $id): ?StaffMember
    {
        try {
            // Banned people ARE returned here: a historical lookup must still
            // name someone who has been removed from assignment.
            $row = $this->db->queryOne(
                'SELECT * FROM staff_members WHERE id = :id AND deleted_at IS NULL',
                ['id' => $id],
            );
        } catch (\Throwable $e) {
            throw $this->failed('read', $e);
        }

        return $row === null ? null : StaffMember::reconstitute($this->normalise($row));
    }

    /** @return list<StaffMember> */
    public function search(StaffQuery $query): array
    {
        $where  = ['deleted_at IS NULL'];
        $params = [];

        // A boolean is BOUND, never written into the SQL as 0 or 1.
        // PostgreSQL's BOOLEAN has no implicit cast from an integer, so
        // `banned = 0` is "operator does not exist: boolean = integer" —
        // a query that works on three drivers and not the fourth.
        $banned = match ($query->status) {
            StaffQuery::ACTIVE => false,
            StaffQuery::BANNED => true,
            default            => null,
        };

        if ($banned !== null) {
            $where[]           = 'banned = :banned';
            $params['banned']  = $banned;
        }

        if ($query->branchId !== null) {
            $where[]             = 'branch_id = :branch_id';
            $params['branch_id'] = $query->branchId;
        }

        if ($query->role !== null) {
            $where[]        = 'role = :role';
            $params['role'] = $query->role->value;
        }

        try {
            $rows = $this->db->query(
                'SELECT * FROM staff_members WHERE ' . implode(' AND ', $where)
                . ' ORDER BY name ASC, id ASC',
                $params,
            );
        } catch (\Throwable $e) {
            throw $this->failed('list', $e);
        }

        return array_map(fn(array $row): StaffMember => StaffMember::reconstitute($this->normalise($row)), $rows);
    }

    public function insert(StaffMember $member): void
    {
        $row = ['id' => $member->id(), 'device_id' => null, 'created_at' => $member->createdAt()] + $member->toRow();

        $columns = array_keys($row);

        try {
            $this->db->execute(
                sprintf(
                    'INSERT INTO staff_members (%s) VALUES (%s)',
                    implode(', ', $columns),
                    implode(', ', array_map(static fn(string $c): string => ':' . $c, $columns)),
                ),
                $row,
            );
        } catch (\Throwable $e) {
            throw $this->failed('insert', $e);
        }
    }

    public function save(StaffMember $member): void
    {
        $row = $member->toRow();

        $assignments = implode(', ', array_map(
            static fn(string $c): string => sprintf('%s = :%s', $c, $c),
            array_keys($row),
        ));

        try {
            $this->db->execute(
                sprintf('UPDATE staff_members SET %s WHERE id = :where_id', $assignments),
                $row + ['where_id' => $member->id()],
            );
        } catch (\Throwable $e) {
            throw $this->failed('update', $e);
        }
    }

    private function failed(string $verb, \Throwable $previous): RepositoryException
    {
        return new RepositoryException(
            sprintf('Failed to %s a staff member.', $verb),
            layer: 'repository.organisation.staff',
            previous: $previous,
        );
    }

    /**
     * Coerce this table's BOOLEAN columns to 1/0 before the Domain sees them.
     *
     * The Domain may not know what a driver is, and the four the kernel
     * supports hand a boolean back four ways — PostgreSQL's arrives as a PHP
     * bool, or as the string 't' when fetches are stringified, and `(bool) 'f'`
     * is true. Normalising here keeps `StaffMember::reconstitute()` reading one
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
