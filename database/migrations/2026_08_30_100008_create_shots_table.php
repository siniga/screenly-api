<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scene_id')->constrained()->cascadeOnDelete();
            $table->foreignId('environment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('environment_asset_id')->nullable()->constrained('environment_assets')->nullOnDelete();
            $table->string('shot_number')->nullable();
            $table->unsignedInteger('order_index')->default(0);
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->text('action')->nullable();
            $table->text('dialogue')->nullable();
            $table->string('shot_size')->nullable();
            $table->string('camera_angle')->nullable();
            $table->string('camera_movement')->nullable();
            $table->string('composition')->nullable();
            $table->string('lens')->nullable();
            $table->string('lighting')->nullable();
            $table->string('mood')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->text('prompt')->nullable();
            $table->string('composition_preset')->nullable();
            $table->string('cinematography_preset')->nullable();
            $table->string('lighting_preset')->nullable();
            $table->string('review_status', 40)->default('draft');
            $table->string('image_status', 40)->default('none');
            $table->text('generation_error')->nullable();
            $table->json('storyboard_settings')->nullable();
            $table->timestamps();

            $table->index(['scene_id', 'order_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shots');
    }
};
