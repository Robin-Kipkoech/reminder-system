<?php

namespace App\Providers;

use App\Models\EmailSetting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->applyEmailSettingsFromDatabase();
    }

    private function applyEmailSettingsFromDatabase(): void
    {
        try {
            // The table won't exist yet while migrating, so skip quietly.
            if (! Schema::hasTable('email_settings')) {
                return;
            }

            $settings = EmailSetting::current();

            if (! $settings || ! $settings->is_enabled) {
                return; // fall back to the .env values
            }

            config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp.url' => null,   // a MAIL_URL in .env must not override us
                'mail.mailers.smtp.host' => $settings->host,
                'mail.mailers.smtp.port' => $settings->port,
                'mail.mailers.smtp.username' => $settings->username,
                'mail.mailers.smtp.password' => $settings->password,
                // Port 587 starts plain and upgrades itself (STARTTLS).
                // Only "ssl" (port 465) needs the secure scheme from the start.
                'mail.mailers.smtp.scheme' => $settings->encryption === 'ssl' ? 'smtps' : 'smtp',
                'mail.from.address' => $settings->from_address,
                'mail.from.name' => $settings->from_name,
            ]);
        } catch (Throwable $e) {
            // If the database is unreachable, never crash the whole app over email settings.
            report($e);
        }
    }
}
