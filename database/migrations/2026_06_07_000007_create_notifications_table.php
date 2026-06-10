<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Targeted, categorized in-app alerts. Mirrors ADVS_Final_Schema.sql §8.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('type', [
                'submission_received', 'processing_complete', 'document_flagged',
                'decision_made', 'high_risk_alert', 'system', 'general',
            ])->default('general')->index();
            $table->string('subject');
            $table->text('body');
            $table->string('sender', 100)->default('ADVS System');
            $table->foreignId('related_submission_id')->nullable()->constrained('submissions')->nullOnDelete();
            $table->boolean('is_read')->default(false)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
