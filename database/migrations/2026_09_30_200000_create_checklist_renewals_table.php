<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_renewals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('checklist_item_id')->constrained()->restrictOnDelete();
            $table->string('reason', 500);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at');
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
            $table->unique(['application_id', 'checklist_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_renewals');
    }
};
