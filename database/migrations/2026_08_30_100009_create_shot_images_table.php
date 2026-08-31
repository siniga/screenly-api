<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shot_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shot_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_number')->default(1);
            $table->text('image_url')->nullable();
            $table->text('thumbnail_url')->nullable();
            $table->text('prompt')->nullable();
            $table->boolean('is_approved')->default(false);
            $table->string('status', 40)->default('pending');
            $table->unsignedInteger('generation_time_ms')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shot_images');
    }
};
