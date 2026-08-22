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
            $table->unsignedInteger('evidence_version')->default(0)->after('state_version');
            $table->unsignedInteger('state_evidence_version')->nullable()->after('evidence_version');
            $table->ulid('analysis_task_id')->nullable()->after('analysis_error')->index();
            $table->string('analysis_stage')->nullable()->after('analysis_task_id');
            $table->timestamp('analysis_started_at')->nullable()->after('analysis_stage');
            $table->timestamp('analysis_finished_at')->nullable()->after('analysis_started_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn([
                'evidence_version',
                'state_evidence_version',
                'analysis_task_id',
                'analysis_stage',
                'analysis_started_at',
                'analysis_finished_at',
            ]);
        });
    }
};
