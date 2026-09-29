<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('section_meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');      // 0 Sunday … 4 Thursday
            $table->string('type', 10);                      // theory|practical|field
            $table->time('starts_at');
            $table->time('ends_at');
            $table->unsignedSmallInteger('minutes');
            $table->string('activity_ar', 30);
            $table->string('building', 20)->nullable();
            $table->string('room', 20)->nullable();
            $table->timestamps();
            $table->unique(['section_id', 'day_of_week', 'starts_at', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('section_meetings');
    }
};
