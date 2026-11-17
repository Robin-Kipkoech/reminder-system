<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('client_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->index()->constrained('users')->restrictOnDelete();
            $table->date('due_date')->index();
            $table->enum('status', ['active', 'renewed', 'expired', 'cancelled'])->default('active')->index();
            $table->boolean('is_recurring')->default(false);
            $table->enum('recurrence_type', ['monthly', 'quarterly', 'yearly', 'custom_days'])->nullable();
            $table->unsignedInteger('recurrence_interval')->nullable();
            $table->timestamp('last_renewed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
