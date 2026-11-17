<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_departments', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->foreignId('item_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->index()->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['item_id', 'department_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_departments');
    }
};
