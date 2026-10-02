<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checklist_items', function (Blueprint $table) {
            $table->unsignedTinyInteger('stage')->default(1)->after('condition');   // 0 department, 1 committee, 2 after approval
            $table->boolean('exemptable')->default(false)->after('stage');
            $table->boolean('official')->default(true)->after('exemptable');       // printed on the official Check List
        });
    }

    public function down(): void
    {
        Schema::table('checklist_items', function (Blueprint $table) {
            $table->dropColumn(['stage', 'exemptable', 'official']);
        });
    }
};
