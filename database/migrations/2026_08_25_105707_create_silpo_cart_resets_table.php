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
        Schema::create('silpo_cart_resets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('plan_state_version');
            $table->char('request_key', 64)->unique();
            $table->string('status')->index();
            $table->string('cart_id');
            $table->char('before_cart_fingerprint', 64);
            $table->char('before_product_fingerprint', 64);
            $table->char('empty_product_fingerprint', 64)->nullable();
            $table->unsignedInteger('items_count');
            $table->decimal('total', 12, 2)->nullable();
            $table->longText('snapshot');
            $table->text('error')->nullable();
            $table->timestamp('cleared_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['event_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('silpo_cart_resets');
    }
};
