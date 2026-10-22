<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('plugin_installations', function (Blueprint $table) {
            $table->string('id', 120)->primary();
            $table->string('installed_version', 50);
            $table->string('status', 20)->default('inactive')->index();
            $table->string('source', 20);
            $table->text('path');
            $table->json('manifest');
            $table->json('files');
            $table->json('settings')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('plugin_installations'); }
};
