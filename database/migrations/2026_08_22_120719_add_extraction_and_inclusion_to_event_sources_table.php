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
        Schema::table('event_sources', function (Blueprint $table) {
            $table->foreignId('image_extraction_id')
                ->nullable()
                ->after('event_id')
                ->constrained()
                ->nullOnDelete();
            $table->string('inclusion')->default('included')->after('status')->index();
            $table->boolean('used_cached_extraction')->default(false)->after('inclusion');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('event_sources', function (Blueprint $table) {
            $table->dropConstrainedForeignId('image_extraction_id');
            $table->dropColumn(['inclusion', 'used_cached_extraction']);
        });
    }
};
