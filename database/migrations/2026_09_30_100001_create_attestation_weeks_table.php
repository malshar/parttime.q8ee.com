<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attestation_weeks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attestation_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('week_number');
            $table->date('date_from');
            $table->date('date_to');
            $table->json('working_days');
            foreach (['courses_text', 'note_ar'] as $col) {
                $table->text($col)->nullable();
                $table->text('generated_'.$col)->nullable();
            }
            foreach (['student_count', 'theory_minutes', 'practical_minutes', 'field_minutes'] as $col) {
                $table->unsignedInteger($col)->default(0);
                $table->unsignedInteger('generated_'.$col)->default(0);
            }
            $table->timestamps();
            $table->unique(['attestation_id', 'week_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attestation_weeks');
    }
};
