<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| ADR-018: the first platform owner is invited by the console, not by a
| person, so an invitation may have no inviter. Expand-only: a NOT NULL is
| dropped and a defaulted column is added; code from the previous release
| keeps working (it always sets invited_by).
|
| `bootstrap` is true exactly when there is no inviter.
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE staff_invitations ALTER COLUMN invited_by DROP NOT NULL');
        DB::statement('ALTER TABLE staff_invitations ADD COLUMN bootstrap boolean NOT NULL DEFAULT false');
        DB::statement('ALTER TABLE staff_invitations ADD CONSTRAINT staff_invitations_bootstrap_check CHECK (bootstrap = (invited_by IS NULL))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE staff_invitations DROP CONSTRAINT staff_invitations_bootstrap_check');
        DB::statement('DELETE FROM staff_invitations WHERE invited_by IS NULL');
        DB::statement('ALTER TABLE staff_invitations DROP COLUMN bootstrap');
        DB::statement('ALTER TABLE staff_invitations ALTER COLUMN invited_by SET NOT NULL');
    }
};
