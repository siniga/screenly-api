<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('objects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('order_index')->default(0);
            $table->string('name');
            $table->string('category')->nullable();
            $table->text('description')->nullable();
            $table->string('material')->nullable();
            $table->string('color')->nullable();
            $table->string('condition')->nullable();
            $table->string('importance')->nullable();
            $table->string('used_by')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 40)->default('suggested');
            $table->text('reference_image_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('objects');
    }
};
