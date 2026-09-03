<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_story_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('story_hash', 64);
            $table->unsignedInteger('story_version')->default(1);
            $table->string('status', 40)->default('pending');
            $table->json('analysis')->nullable();
            $table->string('model', 80)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'story_hash', 'status']);
            $table->index(['project_id', 'status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_story_analyses');
    }
};
