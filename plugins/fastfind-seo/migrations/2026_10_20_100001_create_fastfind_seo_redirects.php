<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('fastfind_seo_redirects')) return;
        Schema::create('fastfind_seo_redirects', function (Blueprint $table) {
            $table->id();
            $table->string('source', 500)->unique();
            $table->string('destination', 1500);
            $table->unsignedSmallInteger('status')->default(301);
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('fastfind_seo_redirects'); }
};
