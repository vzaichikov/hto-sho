<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('plan_generation_status')->default('not_started')->after('plan_state_version')->index();
            $table->text('plan_generation_error')->nullable()->after('plan_generation_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex(['plan_generation_status']);
            $table->dropColumn(['plan_generation_status', 'plan_generation_error']);
        });
    }
};
