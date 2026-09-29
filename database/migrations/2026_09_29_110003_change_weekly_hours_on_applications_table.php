<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Weekly load is stored once, as scheduled minutes; hours are always derived
 * (Application::weeklyHoursLabel()). The milestone-1 integer weekly_hours was never read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('weekly_hours');
        });
        Schema::table('applications', function (Blueprint $table) {
            $table->unsignedInteger('weekly_minutes')->default(0)->after('assignment_decision_date');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('weekly_minutes');
        });
        Schema::table('applications', function (Blueprint $table) {
            $table->unsignedSmallInteger('weekly_hours')->default(0)->after('assignment_decision_date');
        });
    }
};
