<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Legacy identity and home-address fields are no longer collected during
     * vendor registration, but remain nullable for historical records.
     */
    public function up(): void
    {
        Schema::table('vendor_representatives', function (Blueprint $table): void {
            $table->string('government_id_type', 40)->nullable()->change();
            $table->string('government_id_number', 60)->nullable()->change();
            $table->text('home_address')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('vendor_representatives', function (Blueprint $table): void {
            $table->string('government_id_type', 40)->nullable(false)->change();
            $table->string('government_id_number', 60)->nullable(false)->change();
            $table->text('home_address')->nullable(false)->change();
        });
    }
};
