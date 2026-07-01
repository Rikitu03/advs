<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Declared business reference data captured at registration (step 2). These
     * are the franchisee's self-declared values that uploaded documents are later
     * cross-checked against (TIN vs BIR 2303, business name vs DTI/SEC/Mayor's
     * Permit, etc.). The legacy `registration_number` and `address` columns are
     * left in place but are not written by the new flow.
     */
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('trade_name', 150)->nullable()->after('company_name');
            $table->enum('business_entity_type', [
                'sole_proprietorship', 'partnership', 'corporation', 'cooperative',
            ])->nullable()->after('trade_name');
            $table->string('tin', 20)->nullable()->after('business_entity_type');
            $table->string('dti_registration_number', 50)->nullable()->after('tin');
            $table->string('sec_registration_number', 50)->nullable()->after('dti_registration_number');
            $table->string('business_permit_number', 50)->nullable()->after('sec_registration_number');
            $table->string('nature_of_business', 150)->nullable()->after('business_permit_number');
            $table->string('business_street', 255)->nullable()->after('nature_of_business');
            $table->string('business_barangay', 120)->nullable()->after('business_street');
            $table->string('business_city', 120)->nullable()->after('business_barangay');
            $table->string('business_province', 120)->nullable()->after('business_city');
            $table->string('business_postal_code', 10)->nullable()->after('business_province');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn([
                'trade_name', 'business_entity_type', 'tin',
                'dti_registration_number', 'sec_registration_number',
                'business_permit_number', 'nature_of_business',
                'business_street', 'business_barangay', 'business_city',
                'business_province', 'business_postal_code',
            ]);
        });
    }
};
