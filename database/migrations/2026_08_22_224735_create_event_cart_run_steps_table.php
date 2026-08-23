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
        Schema::create('event_cart_run_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_cart_run_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('kind')->index();
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamps();

            $table->unique(['event_cart_run_id', 'sequence']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_cart_run_steps');
    }
};
