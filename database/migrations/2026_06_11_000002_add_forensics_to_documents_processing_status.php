<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add the Stage T `forensics` value to `documents.processing_status` so the
     * tampering stage is observable in the pipeline status. MySQL enforces the
     * enum and needs an explicit MODIFY listing ALL values (existing + new);
     * SQLite (the test DB) stores enums as unconstrained TEXT, so it is a no-op.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(
            'ALTER TABLE documents MODIFY processing_status '.
            "ENUM('queued','preprocessing','ocr','classifying','detecting',".
            "'verifying','forensics','completed','failed') NOT NULL DEFAULT 'queued'"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("UPDATE documents SET processing_status = 'failed' WHERE processing_status = 'forensics'");
        DB::statement(
            'ALTER TABLE documents MODIFY processing_status '.
            "ENUM('queued','preprocessing','ocr','classifying','detecting',".
            "'verifying','completed','failed') NOT NULL DEFAULT 'queued'"
        );
    }
};
