<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The new unique index is created before the old one is dropped: on MySQL the old
        // (application_id, checklist_item_id, version) unique may be the index currently
        // serving the documents.application_id foreign key, and dropping it while it is the
        // only such index fails with error 1553. Creating the new unique first means MySQL
        // always has a qualifying index to fall back to.
        Schema::table('documents', function (Blueprint $table) {
            $table->unsignedSmallInteger('part')->default(1)->after('version');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->unique(['application_id', 'checklist_item_id', 'version', 'part'], 'documents_item_version_part_unique');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->dropUnique(['application_id', 'checklist_item_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->unique(['application_id', 'checklist_item_id', 'version']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->dropUnique('documents_item_version_part_unique');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('part');
        });
    }
};
