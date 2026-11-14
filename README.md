# hkm-plugin-loan-organisation

An [HKM Kernel](https://github.com/AlfaCode-Team/hkm-kernel) plugin providing
**`organisation.structure`** — part of the **akasente** loan-management set.

Branches, the staff posted to them, and the hours each branch keeps. Owns branches, staff_members and business_hours in the tenant template. Every write takes a new sync rev in the same transaction; banning removes a person from assignment but keeps their row readable for historical records.

## Install

```bash
hkm plugins install https://github.com/AlfaCode-Team/hkm-plugin-loan-organisation.git
hkm plugins enable loan-organisation
```

It installs as `plugins/LoanOrganisation` with the namespace `Plugins\LoanOrganisation\`, because
`hkm` takes the folder from `module.json`'s `name`, not from the repository.

## What it owns

Tables, shipped as `database/tenant-template/` migrations and published into a
project on enable:

- `branches`
- `staff_members`
- `business_hours`

Requires the domains: `database.management`, `sync.offline`

Publishes:

- `Plugins\LoanOrganisation\API\Contracts\OrganisationServiceContract`
- `Plugins\LoanOrganisation\API\Contracts\StaffDirectoryContract`

## Routes

| Route | Name |
|---|---|
| `GET /api/organisation/branches` | `organisation.branches.index` |
| `POST /api/organisation/branches` | `organisation.branches.store` |
| `GET /api/organisation/branches/{id:num}` | `organisation.branches.show` |
| `PUT /api/organisation/branches/{id:num}` | `organisation.branches.update` |
| `PUT /api/organisation/branches/{id:num}/manager` | `organisation.branches.manager` |
| `GET /api/organisation/branches/{id:num}/hours` | `organisation.branches.hours.show` |
| `PUT /api/organisation/branches/{id:num}/hours` | `organisation.branches.hours.set` |
| `GET /api/organisation/staff` | `organisation.staff.index` |
| `GET /api/organisation/staff/assignable` | `organisation.staff.assignable` |
| `POST /api/organisation/staff` | `organisation.staff.store` |
| `GET /api/organisation/staff/{id:num}` | `organisation.staff.show` |
| `PUT /api/organisation/staff/{id:num}` | `organisation.staff.update` |
| `POST /api/organisation/staff/{id:num}/ban` | `organisation.staff.ban` |
| `POST /api/organisation/staff/{id:num}/reinstate` | `organisation.staff.reinstate` |
| `PUT /api/organisation/staff/{id:num}/branch` | `organisation.staff.branch` |

## Every write takes a sync revision

Each row carries `rev`, `device_id`, `created_at`, `updated_at` and
`deleted_at`. `rev` comes from `sync.offline`'s counter and is the cursor a
handset pulls against; a delete is a tombstone, never a `DELETE`. Ids are
client-minted from a claimed shard, so a phone with no signal can create a row
that will never collide with one the server made.

## Tests

```bash
HKM_KERNEL_PATH=/path/to/hkm-kernel phpunit
```

Check these out beside this repository (or install them) before running the suite: `hkm-plugin-database`, `hkm-plugin-loan-sync`.

The suite migrates the real tenant template into a throwaway database and
drives the real adapter against it. `HKM_TEST_DB` picks which database that is:

```bash
phpunit                                                  # SQLite (default, no server)
HKM_TEST_DB=mysql  HKM_TEST_DB_USERNAME=root  phpunit
HKM_TEST_DB=pgsql  HKM_TEST_DB_PORT=5432      phpunit
HKM_TEST_DB=sqlsrv HKM_TEST_DB_USERNAME=sa    phpunit
```

The account needs permission to CREATE DATABASE; each run provisions
`hkm_test_<suite>_<random>` and drops it in teardown.

**Run at least one non-SQLite driver before believing a green suite.** SQLite
has no strict mode, no real column types, it tolerates a named placeholder used
twice, and it serialises writers for free — so it hides the whole class of
defect this code is most likely to have.

## Licence

MIT. See [LICENSE](LICENSE).
