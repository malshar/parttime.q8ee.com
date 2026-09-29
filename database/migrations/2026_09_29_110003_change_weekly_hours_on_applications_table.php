<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->unsignedInteger('weekly_minutes')->default(0)->after('weekly_hours');
            $table->decimal('weekly_hours_decimal', 5, 1)->default(0)->after('weekly_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn(['weekly_minutes', 'weekly_hours_decimal']);
        });
    }
};
