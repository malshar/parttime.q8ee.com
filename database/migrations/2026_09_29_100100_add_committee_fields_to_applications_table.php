<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('committee_outcome', 10)->nullable()->after('decided_at');
            $table->date('committee_met_on')->nullable()->after('committee_outcome');
            $table->string('committee_reference', 60)->nullable()->after('committee_met_on');
            $table->text('committee_note')->nullable()->after('committee_reference');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn(['committee_outcome', 'committee_met_on', 'committee_reference', 'committee_note']);
        });
    }
};
