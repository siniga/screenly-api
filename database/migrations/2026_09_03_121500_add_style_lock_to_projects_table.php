<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->text('style_prompt')->nullable()->after('style');
            $table->json('style_meta')->nullable()->after('style_prompt');
            $table->text('style_reference_url')->nullable()->after('style_meta');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['style_prompt', 'style_meta', 'style_reference_url']);
        });
    }
};
