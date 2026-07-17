<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The officer's negative decision is a resubmission request, not a terminal
     * rejection (Negofood compliance lifecycle — the vendor corrects and
     * resubmits). Renames the submissions.status enum value and the audit-trail
     * action recorded for past decisions. Vendors keep their own `rejected`
     * accreditation status; only the submission decision is renamed.
     *
     * Widen → update → narrow keeps the change portable across MySQL and the
     * sqlite test database (enum = CHECK constraint on sqlite).
     */
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->enum('status', ['processing', 'pending_review', 'approved', 'rejected', 'resubmission_requested'])
                ->default('processing')
                ->change();
        });

        DB::table('submissions')->where('status', 'rejected')->update(['status' => 'resubmission_requested']);
        DB::table('audit_logs')->where('action', 'submission.rejected')->update(['action' => 'submission.resubmission_requested']);

        Schema::table('submissions', function (Blueprint $table) {
            $table->enum('status', ['processing', 'pending_review', 'approved', 'resubmission_requested'])
                ->default('processing')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->enum('status', ['processing', 'pending_review', 'approved', 'rejected', 'resubmission_requested'])
                ->default('processing')
                ->change();
        });

        DB::table('submissions')->where('status', 'resubmission_requested')->update(['status' => 'rejected']);
        DB::table('audit_logs')->where('action', 'submission.resubmission_requested')->update(['action' => 'submission.rejected']);

        Schema::table('submissions', function (Blueprint $table) {
            $table->enum('status', ['processing', 'pending_review', 'approved', 'rejected'])
                ->default('processing')
                ->change();
        });
    }
};
