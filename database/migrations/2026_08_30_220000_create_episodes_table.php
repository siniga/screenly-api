<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('episodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('episode_number');
            $table->string('title');
            $table->text('summary')->nullable();
            $table->longText('screenplay')->nullable();
            $table->string('status', 40)->default('planned');
            $table->timestamps();

            $table->unique(['project_id', 'episode_number']);
            $table->index(['project_id', 'episode_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('episodes');
    }
};
