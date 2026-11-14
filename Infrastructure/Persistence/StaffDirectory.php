<?php

declare(strict_types=1);

namespace Plugins\LoanOrganisation\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\LoanOrganisation\API\Contracts\StaffDirectoryContract;

/**
 * The user → staff lookup other modules resolve `recorded_by` through.
 *
 * Reads are memoised per request. A service recording one payment asks for the
 * same staff member two or three times (id, then name, then role), and that is
 * one row, not three round trips.
 *
 * A BANNED staff member still resolves. Their name is already printed on
 * receipts and handovers, and a historical record must keep reading correctly
 * after someone leaves; refusing to name them would turn old rows into
 * anonymous ones. Whether a banned person may create anything NEW is a
 * decision for the calling service, not for a lookup table.
 */
final class StaffDirectory implements StaffDirectoryContract
{
    /** @var array<string, array<string, mixed>|null> */
    private array $byUser = [];

    /** @var array<int, array<string, mixed>|null> */
    private array $byId = [];

    public function __construct(
        private readonly DatabasePort $db,
    ) {
    }

    public function staffIdForUser(string $userId): ?int
    {
        $row = $this->rowForUser($userId);

        return $row === null ? null : (int) $row['id'];
    }

    public function staffName(int $staffId): ?string
    {
        $row = $this->rowForId($staffId);
        $name = $row['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    public function staffRole(int $staffId): ?string
    {
        $row = $this->rowForId($staffId);
        $role = $row['role'] ?? null;

        return is_string($role) && $role !== '' ? $role : null;
    }

    /** @return array<string, mixed>|null */
    private function rowForUser(string $userId): ?array
    {
        if (array_key_exists($userId, $this->byUser)) {
            return $this->byUser[$userId];
        }

        if ($userId === '') {
            return $this->byUser[$userId] = null;
        }

        try {
            $row = $this->db->queryOne(
                'SELECT id, name, role FROM staff_members WHERE user_id = :user AND deleted_at IS NULL',
                ['user' => $userId],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                'Failed to resolve the staff member for a user.',
                layer: 'repository.organisation.directory',
                context: ['user_id' => $userId],
                previous: $e,
            );
        }

        if ($row !== null) {
            $this->byId[(int) $row['id']] = $row;
        }

        return $this->byUser[$userId] = $row;
    }

    /** @return array<string, mixed>|null */
    private function rowForId(int $staffId): ?array
    {
        if (array_key_exists($staffId, $this->byId)) {
            return $this->byId[$staffId];
        }

        try {
            $row = $this->db->queryOne(
                'SELECT id, name, role FROM staff_members WHERE id = :id AND deleted_at IS NULL',
                ['id' => $staffId],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                'Failed to read a staff member.',
                layer: 'repository.organisation.directory',
                context: ['staff_id' => $staffId],
                previous: $e,
            );
        }

        return $this->byId[$staffId] = $row;
    }
}
