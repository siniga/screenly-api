<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('characters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('order_index')->default(0);
            $table->string('name');
            $table->string('role')->nullable();
            $table->string('gender')->nullable();
            $table->string('age_range')->nullable();
            $table->string('ethnicity')->nullable();
            $table->text('description')->nullable();
            $table->text('personality')->nullable();
            $table->text('appearance')->nullable();
            $table->text('wardrobe')->nullable();
            $table->string('importance')->nullable();
            $table->string('status', 40)->default('suggested');
            $table->string('image_status', 40)->default('pending');
            $table->text('prompt')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('characters');
    }
};
