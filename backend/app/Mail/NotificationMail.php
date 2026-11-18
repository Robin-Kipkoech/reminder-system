<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $greetingName;

    public function __construct(
        public User $recipient,
        public string $titleText,
        public string $messageText,
        public ?string $actionUrl = null,
        public ?string $buttonLabel = null,
    ) {
        $fullName = trim((string) $recipient->name);
        $this->greetingName = $fullName !== '' ? (explode(' ', $fullName)[0] ?: $fullName) : 'there';

        $this->subject($this->titleText);
        $redirectTo = config('mail.redirect_to');
        if (is_string($redirectTo) && $redirectTo !== '' && config('app.env') !== 'production') {
            $original = (string) $this->recipient->email;
            $this->subject("[to: {$original}] {$this->titleText}");
        }
    }

    public function build(): static
    {
        return $this->view('mail.notification', [
                'greetingName' => $this->greetingName,
                'titleText' => $this->titleText,
                'messageText' => $this->messageText,
                'actionUrl' => $this->actionUrl,
                'buttonLabel' => $this->buttonLabel ?: 'View in Reminder System',
            ])
            ->text('mail.notification_plain', [
                'greetingName' => $this->greetingName,
                'titleText' => $this->titleText,
                'messageText' => $this->messageText,
                'actionUrl' => $this->actionUrl,
                'buttonLabel' => $this->buttonLabel ?: 'View in Reminder System',
            ]);
    }
}
