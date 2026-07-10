<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The authorized owner/representative of a vendor, one per vendor. These
     * declared identity fields are cross-checked against the submitted
     * government ID and the name printed on the business documents.
     */
    public function up(): void
    {
        Schema::create('vendor_representatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100);
            $table->string('suffix', 20)->nullable();
            $table->date('date_of_birth');
            $table->enum('gender', ['male', 'female']);
            $table->string('contact_number', 20);
            $table->string('government_id_type', 40);
            $table->string('government_id_number', 60);
            $table->text('home_address');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_representatives');
    }
};
