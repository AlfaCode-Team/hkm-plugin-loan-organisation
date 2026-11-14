<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Schema\Blueprint;

/**
 * Branches, the people posted to them, and the hours the desk keeps.
 *
 * A tenant IS a business. Branches are its locations; every officer and cashier
 * belongs to exactly one and is transferable between them.
 */
/**
 * JSON-SHAPED COLUMNS ARE `longText`, NOT `json`, ON PURPOSE.
 *
 * The kernel supports MySQL, PostgreSQL, SQLite and SQL Server, and a `json`
 * column means a different thing on each: MySQL and PostgreSQL REFUSE a value
 * that does not parse, SQLite stores whatever it is handed, and SQL Server has
 * no JSON type at all (it compiles to NVARCHAR(MAX) and validates nothing). So
 * `json` would give this application three different behaviours depending on
 * where it was deployed.
 *
 * It is the permissive one that is correct here. These columns carry what v1
 * and the Firestore import hand over, and some of that does not parse — the
 * Customers suite pins the behaviour the app must have when it meets a blob it
 * cannot read: keep it, show what can be shown, and never silently discard it.
 * A column that rejects the row instead would fail the import, which is the
 * one outcome with no recovery.
 *
 * `longText` holds the same 4GB on MySQL that `json` did, and reads the same
 * on all four. Nothing queries these server-side; every one of them is decoded
 * in PHP.
 */
return new class implements MigrationInterface {

    public function up($schema): void
    {
        $schema->create('branches', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();          // client-minted
            $table->string('name', 160);
            $table->string('code', 32)->nullable();
            $table->string('district', 120)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('phone', 32)->nullable();

            // Nullable ON PURPOSE, and it has to be: a manager is picked from
            // staff, and staff are hired INTO a branch. A required manager
            // deadlocks a new business that has neither. The first person
            // posted here claims it; transferring them out clears it again.
            $table->unsignedBigInteger('manager_staff_id')->nullable();

            $table->date('opened_on')->nullable();
            $table->string('status', 16)->default('open');        // open | closed

            // The money the branch started trading with. NULL means "never set
            // up", which is a different answer from 0 and raises the one-time
            // prompt; 0 is a real answer. `opening_float` is mobile money — it
            // is money the business holds but never money in the drawer.
            $table->unsignedBigInteger('opening_cash')->nullable();
            $table->unsignedBigInteger('opening_float')->nullable();
            $table->dateTime('money_set_at')->nullable();

            $table->unsignedBigInteger('rev')->default(0);
            $table->string('device_id', 64)->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->dateTime('deleted_at')->nullable();

            $table->index(['rev'], 'idx_branches_rev');
            $table->index(['status'], 'idx_branches_status');
        });

        $schema->create('staff_members', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();          // client-minted

            // The central account this person signs in with. Identity is a
            // platform concern (one person, one login, possibly several
            // businesses); what they ARE here is this row.
            // A central user id is a ULID — `Identity->userId` carries
            // users.user_id, a 26-character Crockford base32 string, not
            // users.id. Declared numeric, SQLite stored the ULID anyway and
            // every other driver the kernel supports either refuses the row
            // (MySQL strict, PostgreSQL) or coerces it to 0, which would
            // silently point every device and every staff row at one
            // non-existent account.
            $table->string('user_id', 40)->nullable();

            $table->string('name', 160);
            $table->string('phone', 32)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('role', 16);                           // officer | cashier
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('region', 120)->nullable();            // the patch inside the branch
            $table->boolean('banned')->default(false);
            $table->date('hired_on')->nullable();
            $table->string('photo_path', 255)->nullable();

            $table->unsignedBigInteger('rev')->default(0);
            $table->string('device_id', 64)->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->dateTime('deleted_at')->nullable();

            $table->index(['rev'], 'idx_staff_rev');
            $table->index(['branch_id', 'role'], 'idx_staff_branch_role');
            $table->index(['user_id'], 'idx_staff_user');
            $table->index(['phone'], 'idx_staff_phone');
        });

        // One row per branch; the cashier's opening/closing times and whether
        // the app should raise a "time to close" reminder.
        $schema->create('business_hours', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->longText('days');                                 // [{day,open,close,closed}] × 7
            $table->string('opening_time', 8)->nullable();
            $table->string('closing_time', 8)->nullable();
            $table->boolean('close_reminder')->default(true);

            $table->unsignedBigInteger('rev')->default(0);
            $table->string('device_id', 64)->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->dateTime('deleted_at')->nullable();

            $table->index(['rev'], 'idx_hours_rev');
            $table->index(['branch_id'], 'idx_hours_branch');
        });
    }

    public function down($schema): void
    {
        $schema->dropIfExists('business_hours');
        $schema->dropIfExists('staff_members');
        $schema->dropIfExists('branches');
    }
};
