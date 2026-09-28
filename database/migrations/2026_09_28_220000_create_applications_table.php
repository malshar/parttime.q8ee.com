<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('term_id')->constrained()->restrictOnDelete();
            $table->foreignId('instructor_id')->constrained()->cascadeOnDelete();
            $table->string('status', 15)->default('draft')->index();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('assignment_decision_number', 40)->nullable();
            $table->date('assignment_decision_date')->nullable();
            $table->unsignedSmallInteger('weekly_hours')->default(0);
            $table->text('admin_note')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->unique(['term_id', 'instructor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
