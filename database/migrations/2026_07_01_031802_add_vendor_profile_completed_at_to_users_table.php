<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marks the moment a vendor finished the business/owner-details step (the
     * second registration step, before signature enrollment). Presence of this
     * timestamp is the EnsureVendorProfileComplete gate signal — kept on `users`
     * (like signature_enrolled_at) so the gate reads it off the already-loaded
     * user with no extra query.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('vendor_profile_completed_at')->nullable()->after('signature_enrolled_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('vendor_profile_completed_at');
        });
    }
};
