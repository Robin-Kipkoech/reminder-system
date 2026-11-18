<?php

namespace App\Http\Controllers;

use App\Mail\NotificationMail;
use App\Models\EmailSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AdminEmailSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json($this->settingsPayload(EmailSetting::current()));
    }

    public function update(Request $request): JsonResponse
    {
        $existing = EmailSetting::current();
        $validated = $request->validate([
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'encryption' => ['nullable', 'in:tls,ssl'],
            'username' => ['required', 'string'],
            'password' => $existing === null ? ['required', 'string', 'min:1'] : ['sometimes', 'nullable', 'string'],
            'from_address' => ['required', 'email'],
            'from_name' => ['required', 'string', 'max:100'],
            'is_enabled' => ['required', 'boolean'],
            'reminder_send_time' => ['required', 'date_format:H:i'],
            'send_overdue_notices' => ['required', 'boolean'],
        ]);

        $oldValues = $existing === null ? null : $this->auditValues($existing);
        $settings = DB::transaction(function () use ($validated, $existing, $request, $oldValues): EmailSetting {
            $settings = $existing ?? new EmailSetting;
            $settings->fill([
                'host' => $validated['host'],
                'port' => $validated['port'],
                'encryption' => $validated['encryption'] ?? null,
                'username' => $validated['username'],
                'from_address' => $validated['from_address'],
                'from_name' => $validated['from_name'],
                'is_enabled' => $validated['is_enabled'],
                'reminder_send_time' => $validated['reminder_send_time'],
                'send_overdue_notices' => $validated['send_overdue_notices'],
                'updated_by' => $request->user()->getKey(),
            ]);
            if (isset($validated['password']) && $validated['password'] !== '') {
                $settings->password = $validated['password'];
            }
            $settings->save();

            DB::table('audit_logs')->insert([
                'user_id' => $request->user()->getKey(),
                'action' => 'email-settings-updated',
                'entity_type' => 'email_settings',
                'entity_id' => $settings->getKey(),
                'old_values' => $oldValues === null ? null : json_encode($oldValues, JSON_THROW_ON_ERROR),
                'new_values' => json_encode($this->auditValues($settings) + [
                    'password_changed' => isset($validated['password']) && $validated['password'] !== '',
                ], JSON_THROW_ON_ERROR),
                'ip_address' => $request->ip(),
                'created_at' => now(),
            ]);

            return $settings;
        });

        Artisan::call('queue:restart');

        return response()->json($this->settingsPayload($settings->fresh()));
    }

    public function test(Request $request): JsonResponse
    {
        $admin = $request->user();
        $key = 'email-settings-test:'.$admin->getKey();
        if (RateLimiter::tooManyAttempts($key, 6)) {
            return response()->json(['ok' => false, 'message' => 'Too many test emails. Try again in a minute.'], 429);
        }
        RateLimiter::hit($key, 60);

        $validated = $request->validate(['to' => ['sometimes', 'email']]);
        $settings = EmailSetting::current();
        $to = $validated['to'] ?? $admin->email;
        $redirect = $this->activeRedirect();
        $target = $redirect ?? $to;

        try {
            if ($settings !== null) {
                $this->applySettingsToMailer($settings);
            }
            $mail = new NotificationMail(
                $admin,
                'Email settings test',
                'This is a test email from the TechRon Reminder System.',
                null,
            );
            if ($redirect !== null) {
                $mail->subject("[to: {$to}] Email settings test");
            }
            Mail::mailer('smtp')->to($target)->send($mail);
            $this->persistTestResult($settings, 'success', null);

            return response()->json(['ok' => true, 'message' => 'Test email sent successfully.']);
        } catch (Throwable $exception) {
            $reason = $this->mailFailureReason($exception);
            $this->persistTestResult($settings, 'failed', $reason);

            return response()->json(['ok' => false, 'message' => $reason], 422);
        }
    }

    public function logs(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'in:sent,failed,pending,skipped'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        $perPage = (int) ($validated['per_page'] ?? 15);
        $query = DB::table('notification_logs')
            ->leftJoin('users', 'users.id', '=', 'notification_logs.user_id')
            ->where('notification_logs.channel', 'email');
        if (isset($validated['status'])) {
            $query->where('notification_logs.status', $validated['status']);
        }

        $paginator = $query->select([
            'notification_logs.id',
            'notification_logs.user_id',
            'users.name as user_name',
            'users.email as user_email',
            'notification_logs.title',
            'notification_logs.status',
            'notification_logs.sent_at',
            'notification_logs.error_message',
            'notification_logs.source_type',
            'notification_logs.source_id',
            'notification_logs.created_at',
        ])->orderByDesc('notification_logs.created_at')->orderByDesc('notification_logs.id')
            ->paginate($perPage);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (object $row): array => [
                'id' => $row->id,
                'user' => $row->user_id === null ? null : [
                    'id' => $row->user_id,
                    'name' => $row->user_name,
                    'email' => $row->user_email,
                ],
                'title' => $row->title,
                'status' => $row->status,
                'sent_at' => $row->sent_at,
                'error_message' => $row->error_message,
                'source_type' => $row->source_type,
                'source_id' => $row->source_id,
                'created_at' => $row->created_at,
            ])->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    private function settingsPayload(?EmailSetting $settings): array
    {
        $database = $settings !== null;
        $redirect = $this->activeRedirect();
        $fromAddress = $settings?->from_address ?? config('mail.from.address');
        $fromName = $settings?->from_name ?? config('mail.from.name');

        return [
            'configured' => $database,
            'source' => $database ? 'database' : 'env',
            'host' => $settings?->host ?? config('mail.mailers.smtp.host'),
            'port' => $settings === null ? (int) config('mail.mailers.smtp.port') : $settings->port,
            'encryption' => $settings === null ? $this->envEncryption() : $settings->encryption,
            'username' => $settings?->username ?? config('mail.mailers.smtp.username'),
            'has_password' => $database ? filled($settings->password) : filled(config('mail.mailers.smtp.password')),
            'from_address' => $fromAddress,
            'from_name' => $fromName,
            'is_enabled' => $settings?->is_enabled ?? true,
            'reminder_send_time' => substr((string) ($settings?->reminder_send_time ?? '08:00:00'), 0, 5),
            'send_overdue_notices' => $settings?->send_overdue_notices ?? true,
            'last_test_at' => $settings?->last_test_at,
            'last_test_status' => $settings?->last_test_status,
            'last_test_error' => $settings?->last_test_error,
            'updated_at' => $settings?->updated_at,
            'redirect_to' => $redirect,
            'queue' => [
                'pending' => Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0,
                'failed' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0,
            ],
        ];
    }

    private function auditValues(EmailSetting $settings): array
    {
        return [
            'host' => $settings->host,
            'port' => $settings->port,
            'encryption' => $settings->encryption,
            'username' => $settings->username,
            'from_address' => $settings->from_address,
            'from_name' => $settings->from_name,
            'is_enabled' => $settings->is_enabled,
            'reminder_send_time' => substr((string) $settings->reminder_send_time, 0, 5),
            'send_overdue_notices' => $settings->send_overdue_notices,
        ];
    }

    private function envEncryption(): ?string
    {
        return match (config('mail.mailers.smtp.scheme')) {
            'smtps' => 'ssl',
            'smtp' => 'tls',
            default => null,
        };
    }

    private function activeRedirect(): ?string
    {
        $redirect = config('mail.redirect_to');

        return config('app.env') !== 'production' && is_string($redirect) && $redirect !== '' ? $redirect : null;
    }

    private function applySettingsToMailer(EmailSetting $settings): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.url' => null,
            'mail.mailers.smtp.host' => $settings->host,
            'mail.mailers.smtp.port' => $settings->port,
            'mail.mailers.smtp.username' => $settings->username,
            'mail.mailers.smtp.password' => $settings->password,
            'mail.mailers.smtp.scheme' => $settings->encryption === 'ssl' ? 'smtps' : 'smtp',
            'mail.from.address' => $settings->from_address,
            'mail.from.name' => $settings->from_name,
        ]);
        $mailManager = Mail::getFacadeRoot();
        if (method_exists($mailManager, 'purge')) {
            $mailManager->purge('smtp');
        }
    }

    private function persistTestResult(?EmailSetting $settings, string $status, ?string $error): void
    {
        if ($settings === null) {
            return;
        }

        $settings->forceFill([
            'last_test_at' => now(),
            'last_test_status' => $status,
            'last_test_error' => $error,
        ])->save();
    }

    private function mailFailureReason(Throwable $exception): string
    {
        $message = strtolower((string) $exception->getMessage());
        if (str_contains($message, 'auth') || str_contains($message, 'credentials') || str_contains($message, 'password')) {
            return 'Authentication failed. Check the SMTP username and password.';
        }
        if (str_contains($message, 'tls') || str_contains($message, 'ssl') || str_contains($message, 'certificate')) {
            return 'A TLS/SSL problem prevented the test email from being sent.';
        }
        if (str_contains($message, 'connect') || str_contains($message, 'connection') || str_contains($message, 'timed out')) {
            return 'Could not connect to the mail server. Check the host, port, and network access.';
        }

        return 'The test email could not be sent. Check the mail server settings.';
    }
}
