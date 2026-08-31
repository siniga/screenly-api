<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shot_object', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('object_id')->constrained('objects')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['shot_id', 'object_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shot_object');
    }
};
