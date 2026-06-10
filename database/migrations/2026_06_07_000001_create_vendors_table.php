<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vendor company profiles, one per vendor user account.
     * Mirrors ADVS_Final_Schema.sql §2.
     */
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('company_name', 150);
            $table->string('registration_number', 50)->nullable();
            $table->string('phone_number', 20)->nullable()->unique();
            $table->text('address')->nullable();
            $table->double('risk_score')->default(0);
            $table->enum('status', ['pending', 'under_review', 'approved', 'rejected'])->default('pending')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};
