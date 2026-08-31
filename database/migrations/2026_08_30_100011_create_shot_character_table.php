<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shot_character', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('character_asset_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['shot_id', 'character_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shot_character');
    }
};
