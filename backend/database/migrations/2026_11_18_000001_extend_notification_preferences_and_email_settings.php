<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->boolean('email');
            $table->boolean('in_app');
            $table->timestamps();
            $table->unique(['user_id', 'type']);
        });

        Schema::table('email_settings', function (Blueprint $table): void {
            $table->time('reminder_send_time')->default('08:00:00');
            $table->boolean('send_overdue_notices')->default(true);
            $table->timestamp('last_test_at')->nullable();
            $table->string('last_test_status')->nullable();
            $table->text('last_test_error')->nullable();
        });

        if (Schema::hasTable('notification_logs')) {
            if (DB::getDriverName() === 'mysql') {
                $status = DB::selectOne("SHOW COLUMNS FROM notification_logs LIKE 'status'");
                if ($status && ! str_contains((string) $status->Type, "'skipped'")) {
                    DB::statement("ALTER TABLE notification_logs MODIFY status ENUM('pending', 'sent', 'failed', 'skipped') NOT NULL DEFAULT 'pending'");
                }
            }

            $indexes = collect(Schema::getIndexes('notification_logs'))->pluck('name')->all();
            if (! in_array('notification_logs_channel_status_index', $indexes, true)) {
                Schema::table('notification_logs', function (Blueprint $table): void {
                    $table->index(['channel', 'status'], 'notification_logs_channel_status_index');
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('notification_logs')) {
            $indexes = collect(Schema::getIndexes('notification_logs'))->pluck('name')->all();
            if (in_array('notification_logs_channel_status_index', $indexes, true)) {
                Schema::table('notification_logs', function (Blueprint $table): void {
                    $table->dropIndex('notification_logs_channel_status_index');
                });
            }
        }

        Schema::table('email_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'reminder_send_time',
                'send_overdue_notices',
                'last_test_at',
                'last_test_status',
                'last_test_error',
            ]);
        });

        Schema::dropIfExists('user_notification_preferences');
    }
};
