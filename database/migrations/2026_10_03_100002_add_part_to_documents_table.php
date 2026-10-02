<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->unsignedSmallInteger('part')->default(1)->after('version');
            $table->dropUnique(['application_id', 'checklist_item_id', 'version']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->unique(['application_id', 'checklist_item_id', 'version', 'part'], 'documents_item_version_part_unique');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropUnique('documents_item_version_part_unique');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->unique(['application_id', 'checklist_item_id', 'version']);
            $table->dropColumn('part');
        });
    }
};
