<?php

namespace App\Jobs;

use App\Mail\NotificationMail;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendNotificationEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public int $logId,
        public int $userId,
        public string $title,
        public string $message,
        public ?string $actionUrl = null,
        public ?string $buttonLabel = null,
    ) {}

    public function handle(): void
    {
        $user = User::query()->find($this->userId);
        if (! $user) {
            $this->markFailed('Recipient not found.');

            return;
        }

        $to = (string) $user->email;
        $redirectTo = config('mail.redirect_to');
        if (is_string($redirectTo) && $redirectTo !== '' && config('app.env') !== 'production') {
            $to = $redirectTo;
        }

        try {
            Mail::to($to)->send(new NotificationMail(
                $user,
                $this->title,
                $this->message,
                $this->actionUrl,
                $this->buttonLabel,
            ));

            DB::table('notification_logs')
                ->where('id', $this->logId)
                ->update([
                    'status' => 'sent',
                    'sent_at' => now(),
                    'error_message' => null,
                    'updated_at' => now(),
                ]);
        } catch (Throwable $e) {
            $this->markFailed($this->shortError($e));
            throw $e;
        }
    }

    public function failed(?Throwable $e): void
    {
        $this->markFailed($e ? $this->shortError($e) : 'Failed to send notification email.');
    }

    private function markFailed(string $message): void
    {
        DB::table('notification_logs')
            ->where('id', $this->logId)
            ->update([
                'status' => 'failed',
                'error_message' => $message,
                'updated_at' => now(),
            ]);
    }

    private function shortError(Throwable $e): string
    {
        $message = strtolower((string) $e->getMessage());
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
