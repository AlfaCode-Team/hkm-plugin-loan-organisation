<?php

declare(strict_types=1);

namespace Plugins\LoanOrganisation\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;

/**
 * Client-minted ids for rows the web creates.
 *
 * Every business table keys on an id a handset mints, so the server cannot use
 * AUTO_INCREMENT or lastInsertId() without two sources of ids colliding. A web
 * row takes the legacy shard-0 range, below 1,000,000: the MAX in that range
 * plus one. Tombstones count, because an id is never reissued.
 *
 * MUST be called inside the transaction that inserts the row, after the rev is
 * reserved, so two writers cannot read the same MAX.
 */
final class IdAllocator
{
    /**
     * THE SERVER'S OWN ID WINDOW.
     *
     * Ids are partitioned so that no two minters can collide without
     * coordinating: shard 0 is the LEGACY range (pre-sharding rows, and
     * everything the Firestore import brings over), shards 1..999_998 belong
     * to handsets, and shard 999_999 is the server. Web rows are minted here.
     *
     * They used to be minted in the LEGACY range as MAX(id)+1 — the same range
     * the import writes into. Create a loan on the web today, import a v1 loan
     * carrying that id next week, and one silently overwrites the other.
     *
     * Mirrors ShardClaim::SERVER_FLOOR / SERVER_CEILING in the Sync module.
     * Duplicated rather than imported, because a module may not reach into
     * another module's Domain.
     */
    private const int SERVER_FLOOR   = 999999000000;
    private const int SERVER_CEILING = 999999999999;

    /** The only tables this module mints ids for. */
    private const array TABLES = ['branches', 'staff_members', 'business_hours'];

    public function __construct(
        private readonly DatabasePort $db,
    ) {
    }

    /** @throws RepositoryException */
    public function reserve(string $table): int
    {
        if (!in_array($table, self::TABLES, true)) {
            throw new RepositoryException('Unknown table for id allocation.', layer: 'repository.organisation.ids');
        }

        try {
            $row = $this->db->queryOne(
                sprintf('SELECT MAX(id) AS top FROM %s WHERE id >= :floor AND id <= :ceiling', $table),
                ['floor' => self::SERVER_FLOOR, 'ceiling' => self::SERVER_CEILING],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to read the id range.', layer: 'repository.organisation.ids', previous: $e);
        }

        $next = max((int) ($row['top'] ?? 0) + 1, self::SERVER_FLOOR);

        if ($next > self::SERVER_CEILING) {
            throw new RepositoryException(
                sprintf('The legacy id range for %s is exhausted.', $table),
                layer: 'repository.organisation.ids',
            );
        }

        return $next;
    }
}
