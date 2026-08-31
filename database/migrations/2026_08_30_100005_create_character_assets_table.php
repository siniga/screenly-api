<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->string('asset_type', 80);
            $table->string('title')->nullable();
            $table->text('image_url')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->string('status', 40)->default('pending');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['character_id', 'asset_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_assets');
    }
};
