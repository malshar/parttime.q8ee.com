<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Spec 5b §7: salary is collected after committee approval, not at profile completion,
// so the instructor record must be creatable/saveable without it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instructors', function (Blueprint $table) {
            $table->text('basic_salary')->nullable()->change();
            $table->text('total_salary')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('instructors', function (Blueprint $table) {
            $table->text('basic_salary')->nullable(false)->change();
            $table->text('total_salary')->nullable(false)->change();
        });
    }
};
