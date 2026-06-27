<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('retention_policies', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            $table->string('data_type');
            $table->unsignedSmallInteger('retention_days');
            $table->boolean('archive_enabled')->default(true);
            $table->unsignedSmallInteger('archive_after_days')->nullable();
            $table->boolean('deletion_enabled')->default(false);
            $table->unsignedSmallInteger('deletion_after_days')->nullable();
            $table->boolean('enabled')->default(true);
            $table->json('rules')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('retention_policies');
    }
};
