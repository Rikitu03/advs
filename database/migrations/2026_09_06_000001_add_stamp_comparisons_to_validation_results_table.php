<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('validation_results', function (Blueprint $table): void {
            $table->json('stamp_comparisons')->nullable()->after('stamp_passed');
        });
    }

    public function down(): void
    {
        Schema::table('validation_results', function (Blueprint $table): void {
            $table->dropColumn('stamp_comparisons');
        });
    }
};
