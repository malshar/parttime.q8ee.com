<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('checklist_item_id')->constrained()->restrictOnDelete();
            $table->string('path', 255);
            $table->string('original_name', 255);
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->string('status', 10)->default('pending'); // pending|accepted|rejected
            $table->text('rejection_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedSmallInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['application_id', 'checklist_item_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
