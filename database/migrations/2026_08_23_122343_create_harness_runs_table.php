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
        Schema::create('harness_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('status')->default('running');
            $table->string('correlation_id', 120);
            $table->json('metadata')->nullable();
            $table->unsignedInteger('next_sequence')->default(1);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'type', 'correlation_id']);
            $table->index(['event_id', 'created_at']);
            $table->index(['event_id', 'type', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('harness_runs');
    }
};
