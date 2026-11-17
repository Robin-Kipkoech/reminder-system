<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->enum('source_type', ['task', 'item']);
            $table->unsignedBigInteger('source_id');
            $table->foreignId('user_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('reminder_period_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->date('due_date')->index();
            $table->enum('channel', ['push', 'email', 'in_app']);
            $table->string('title');
            $table->text('message');
            $table->timestamp('scheduled_for');
            $table->timestamp('sent_at')->nullable();
            $table->enum('status', ['pending', 'sent', 'failed'])->default('pending')->index();
            $table->text('error_message')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->unsignedBigInteger('reminder_period_key')->storedAs('COALESCE(reminder_period_id, 0)');
            $table->timestamps();
            $table->unique(
                ['source_type', 'source_id', 'user_id', 'reminder_period_id', 'due_date', 'channel'],
                'notification_logs_dedupe_unique'
            );
            $table->unique(
                ['source_type', 'source_id', 'user_id', 'reminder_period_key', 'due_date', 'channel'],
                'notification_logs_nullsafe_dedupe_unique'
            );
            $table->index(['source_type', 'source_id']);
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
