<?php

namespace App\Jobs;

use App\Mail\ReminderDigestMail;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendReminderDigestEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [60, 300, 900];

    /**
     * @param  array<int,int>  $logIds
     * @param  array<int,array{source_type:string,title:string,client:string,due_date:string,days_remaining:int,url:string}>  $entries
     */
    public function __construct(public int $userId, public array $logIds, public array $entries) {}

    public function handle(): void
    {
        $user = User::query()->where('status', 'active')->find($this->userId);
        if ($user === null) {
            $this->markFailed('Recipient is no longer active.');

            return;
        }

        $to = (string) $user->email;
        $redirectTo = config('mail.redirect_to');
        if (is_string($redirectTo) && $redirectTo !== '' && config('app.env') !== 'production') {
            $to = $redirectTo;
        }

        try {
            Mail::to($to)->send(new ReminderDigestMail($user, $this->entries));
            DB::table('notification_logs')->whereIn('id', $this->logIds)->update([
                'status' => 'sent',
                'sent_at' => now(),
                'error_message' => null,
                'updated_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $this->markFailed($this->safeError($exception));
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->markFailed($exception ? $this->safeError($exception) : 'Mail delivery failed.');
    }

    private function markFailed(string $message): void
    {
        DB::table('notification_logs')->whereIn('id', $this->logIds)->update([
            'status' => 'failed',
            'error_message' => $message,
            'updated_at' => now(),
        ]);
    }

    private function safeError(Throwable $exception): string
    {
        $message = strtolower((string) $exception->getMessage());
        if (str_contains($message, 'auth') || str_contains($message, 'credentials') || str_contains($message, 'password')) {
            return 'Mail authentication failed.';
        }
        if (str_contains($message, 'tls') || str_contains($message, 'ssl') || str_contains($message, 'certificate')) {
            return 'Mail TLS connection failed.';
        }
        if (str_contains($message, 'connect') || str_contains($message, 'connection') || str_contains($message, 'timed out')) {
            return 'Could not connect to the mail server.';
        }

        return 'Mail delivery failed.';
    }
}
