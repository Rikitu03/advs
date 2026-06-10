<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A batch of documents uploaded in one accreditation request — the unit
     * the compliance officer reviews. Mirrors ADVS_Final_Schema.sql §4.
     */
    public function up(): void
    {
        Schema::create('submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['processing', 'pending_review', 'approved', 'rejected'])->default('processing')->index();
            $table->double('composite_risk_score')->nullable();
            $table->enum('risk_level', ['low', 'medium', 'high'])->nullable()->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_comments')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submissions');
    }
};
