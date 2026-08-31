<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('environment_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('scene_number');
            $table->unsignedInteger('order_index')->default(0);
            $table->string('title');
            $table->string('location')->nullable();
            $table->string('time_of_day')->nullable();
            $table->text('description')->nullable();
            $table->string('mood')->nullable();
            $table->string('visual_style')->nullable();
            $table->string('status', 40)->default('draft');
            $table->text('generation_error')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'order_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scenes');
    }
};
