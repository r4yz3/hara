<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Spec 5.2, seq 001.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
    }

    public function down(): void
    {
        // The extension is left installed: other databases objects may depend
        // on it, and dropping it is an operator decision, not a rollback step.
    }
};
