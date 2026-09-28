<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instructors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('full_name', 150);
            $table->text('civil_id');                        // encrypted
            $table->string('civil_id_hash', 64)->unique();   // HMAC for uniqueness/lookup
            $table->date('civil_id_expires_on');
            $table->string('nationality', 60);
            $table->string('mobile', 20);
            $table->string('work_phone', 20)->nullable();
            $table->string('home_phone', 20)->nullable();
            $table->string('employer', 150);
            $table->string('employer_sector', 12);           // government|private
            $table->string('job_title', 120);
            $table->string('highest_degree', 10);            // bachelor|master|phd
            $table->string('degree_title', 150);
            $table->char('degree_country', 2);               // ISO alpha-2; KW = local
            $table->date('degree_obtained_on');
            $table->unsignedTinyInteger('experience_years')->nullable();
            $table->string('bank_name', 120);
            $table->string('bank_branch', 120)->nullable();
            $table->text('iban');                            // encrypted
            $table->text('basic_salary');                    // encrypted
            $table->text('total_salary');                    // encrypted
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instructors');
    }
};
