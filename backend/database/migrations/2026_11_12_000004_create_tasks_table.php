<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('department_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->index()->constrained('users')->restrictOnDelete();
            $table->date('due_date')->index();
            $table->enum('visibility', ['everyone', 'involved_only'])->default('everyone');
            $table->enum('status', ['open', 'in_progress', 'done'])->default('open')->index();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->boolean('is_recurring')->default(false);
            $table->enum('recurrence_type', ['daily', 'weekly', 'monthly', 'yearly', 'custom_days'])->nullable();
            $table->unsignedInteger('recurrence_interval')->nullable();
            $table->foreignId('parent_task_id')->nullable()->index()->constrained('tasks')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
