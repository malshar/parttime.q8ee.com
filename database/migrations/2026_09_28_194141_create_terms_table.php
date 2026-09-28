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
        Schema::create('terms', function (Blueprint $table) {
            $table->id();
            $table->string('academic_year', 9);          // 2026-2027
            $table->string('type', 10);                  // first|second|summer
            $table->date('teaching_starts_on');
            $table->date('teaching_ends_on');
            $table->string('status', 10)->default('open'); // open|closed|archived
            $table->timestamps();
            $table->unique(['academic_year', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('terms');
    }
};
