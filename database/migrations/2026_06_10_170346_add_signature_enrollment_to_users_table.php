<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reference signature captured during vendor registration (second step,
     * before email verification). The image is stored privately; presence of
     * `signature_enrolled_at` marks the vendor as enrolled.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('signature_path', 500)->nullable()->after('role');
            $table->timestamp('signature_enrolled_at')->nullable()->after('signature_path');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['signature_path', 'signature_enrolled_at']);
        });
    }
};
