<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracked_element_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tracked_element_id')->constrained('tracked_elements')->cascadeOnDelete();
            $table->longText('old_value')->nullable();
            $table->longText('new_value')->nullable();
            $table->boolean('has_changed')->default(false);
            $table->string('status')->default('success'); // 'success', 'error'
            $table->text('error_message')->nullable();
            $table->timestamp('checked_at')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracked_element_logs');
    }
};
