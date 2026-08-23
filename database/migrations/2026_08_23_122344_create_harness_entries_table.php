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
        Schema::create('harness_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('harness_run_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('kind')->index();
            $table->string('status')->default('completed');
            $table->string('title');
            $table->text('message')->nullable();
            $table->string('method', 12)->nullable();
            $table->text('endpoint')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['harness_run_id', 'sequence']);
            $table->index(['harness_run_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('harness_entries');
    }
};
