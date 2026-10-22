<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->json('extensions')->nullable();
            $table->timestamp('scheduled_at')->nullable()->index();
        });
        Schema::table('media_assets', function (Blueprint $table) {
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('thumbnail_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('media_assets', fn (Blueprint $table) => $table->dropColumn(['width', 'height', 'thumbnail_path']));
        Schema::table('pages', fn (Blueprint $table) => $table->dropIndex(['scheduled_at']));
        Schema::table('pages', fn (Blueprint $table) => $table->dropColumn(['extensions', 'scheduled_at']));
    }
};
