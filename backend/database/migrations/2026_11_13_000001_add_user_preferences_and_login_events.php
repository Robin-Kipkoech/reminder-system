<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'notify_email')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->boolean('notify_email')->default(true);
                $table->boolean('notify_push')->default(true);
                $table->time('digest_time')->nullable();
            });
        }

        if (! Schema::hasTable('login_events')) {
            Schema::create('login_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('email');
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->boolean('successful');
                $table->timestamp('created_at')->useCurrent();
                $table->index(['user_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('login_events');
        if (Schema::hasColumn('users', 'notify_email')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn(['notify_email', 'notify_push', 'digest_time']);
            });
        }
    }
};
