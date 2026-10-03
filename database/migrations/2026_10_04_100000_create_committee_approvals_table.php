<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('committee_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instructor_id')->constrained()->cascadeOnDelete();
            $table->string('academic_year', 9);
            $table->string('kind', 12);        // initial|renewal
            $table->string('outcome', 12);     // approved|not_renewed
            $table->date('committee_met_on');
            $table->string('committee_reference', 60);
            $table->string('note', 500)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['instructor_id', 'academic_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('committee_approvals');
    }
};
