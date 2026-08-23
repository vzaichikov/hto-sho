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
        Schema::create('event_cart_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('mode');
            $table->string('status')->index();
            $table->string('phase');
            $table->unsignedInteger('plan_state_version');
            $table->unsignedInteger('cursor')->default(0);
            $table->string('cart_id');
            $table->char('delivery_fingerprint', 64);
            $table->json('cart_context');
            $table->json('state');
            $table->json('staged_items')->nullable();
            $table->json('warnings')->nullable();
            $table->text('blocker')->nullable();
            $table->text('error')->nullable();
            $table->decimal('estimated_total', 12, 2)->nullable();
            $table->decimal('actual_total', 12, 2)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'status']);
            $table->index(['event_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_cart_runs');
    }
};
