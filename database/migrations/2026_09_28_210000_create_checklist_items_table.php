<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_items', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('label_ar', 200);
            $table->string('note_ar', 200)->nullable();
            $table->unsignedTinyInteger('sort_order');
            $table->string('provided_by', 12);   // applicant|department
            $table->string('condition', 20);     // always|foreign_degree|private_sector|bachelor_only
            $table->boolean('renews_each_term')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_items');
    }
};
