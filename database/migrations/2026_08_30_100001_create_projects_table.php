<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 150);
            $table->string('style', 100)->default('cinematic_realistic');
            $table->longText('story')->nullable();
            $table->longText('script')->nullable();
            $table->longText('screenplay')->nullable();
            $table->string('current_step', 40)->default('story');
            $table->string('status', 40)->default('draft');
            $table->text('cover_image_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
