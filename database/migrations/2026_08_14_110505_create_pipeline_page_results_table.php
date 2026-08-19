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
        Schema::create('pipeline_page_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pipeline_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('page_index');
            $table->enum('status', ['completed', 'skipped', 'failed'])->default('completed');
            $table->json('stages');
            $table->json('flags')->nullable();
            $table->json('timings')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['pipeline_run_id', 'page_index']);
            $table->index(['document_id', 'page_index']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pipeline_page_results');
    }
};
