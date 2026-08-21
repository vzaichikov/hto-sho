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
        Schema::create('silpo_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('client_id')->nullable();
            $table->text('client_secret')->nullable();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->string('token_type')->default('Bearer');
            $table->text('scope')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('profile_snapshot');
            $table->timestamp('profile_synced_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('silpo_connections');
    }
};
