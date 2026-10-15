<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracked_elements', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('url');
            $table->string('selector');
            $table->string('attribute')->default('text'); // 'text', 'src', 'href', 'innerHTML'
            $table->longText('current_value')->nullable();
            $table->string('status')->default('active'); // 'active', 'paused', 'error'
            $table->text('error_message')->nullable();
            $table->integer('check_interval_minutes')->default(5);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracked_elements');
    }
};
