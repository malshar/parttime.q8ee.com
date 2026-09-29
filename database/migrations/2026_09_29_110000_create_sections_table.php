<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('term_id')->constrained()->cascadeOnDelete();
            $table->string('course_code', 12);
            $table->string('course_name_ar', 150);
            $table->string('section_number', 6);
            $table->string('reference_number', 20)->nullable();
            $table->unsignedSmallInteger('seats_capacity')->nullable();
            $table->unsignedSmallInteger('seats_registered')->nullable();
            $table->unsignedSmallInteger('seats_remaining')->nullable();
            $table->string('scheduled_instructor', 150)->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->boolean('missing_since_import')->default(false);
            $table->timestamps();
            $table->unique(['term_id', 'course_code', 'section_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sections');
    }
};
