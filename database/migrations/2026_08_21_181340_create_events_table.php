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
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title')->default('Нова подія');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('people_count')->nullable();
            $table->string('status')->default('draft')->index();
            $table->json('state')->nullable();
            $table->unsignedInteger('state_version')->default(0);
            $table->json('shopping_plan')->nullable();
            $table->unsignedInteger('plan_state_version')->nullable();
            $table->decimal('budget_amount', 12, 2)->nullable();
            $table->decimal('estimated_total', 12, 2)->nullable();
            $table->char('currency', 3)->default('UAH');
            $table->text('analysis_error')->nullable();
            $table->string('silpo_cart_id')->nullable();
            $table->string('cart_sync_status')->default('not_synced')->index();
            $table->unsignedInteger('cart_synced_state_version')->nullable();
            $table->timestamp('cart_synced_at')->nullable();
            $table->text('cart_sync_error')->nullable();
            $table->timestamp('last_source_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'updated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
