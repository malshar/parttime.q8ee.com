<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('kind', 12)->default('initial')->after('status');   // initial|continuation
        });
        Schema::table('applications', function (Blueprint $table) {
            $table->foreignId('approval_id')->nullable()->after('kind')->constrained('committee_approvals')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approval_id');
        });
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
