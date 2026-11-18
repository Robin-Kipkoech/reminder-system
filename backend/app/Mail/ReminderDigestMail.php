<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ReminderDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $greetingName;

    /** @var array<string, array<int, array{source_type:string,title:string,client:string,due_date:string,days_remaining:int,url:string}>> */
    public array $groups;

    /**
     * @param  array<int, array{source_type:string,title:string,client:string,due_date:string,days_remaining:int,url:string}>  $entries
     */
    public function __construct(public User $recipient, public array $entries)
    {
        $name = trim((string) $recipient->name);
        $this->greetingName = $name !== '' ? (explode(' ', $name)[0] ?: $name) : 'there';
        $this->groups = [
            'Tasks' => array_values(array_filter($entries, fn (array $entry): bool => $entry['source_type'] === 'task')),
            'Items' => array_values(array_filter($entries, fn (array $entry): bool => $entry['source_type'] === 'item')),
        ];
        $this->subject('Your Reminder System digest');
        $redirectTo = config('mail.redirect_to');
        if (is_string($redirectTo) && $redirectTo !== '' && config('app.env') !== 'production') {
            $this->subject("[to: {$recipient->email}] Your Reminder System digest");
        }
    }

    public function build(): static
    {
        $viewData = [
            'greetingName' => $this->greetingName,
            'entries' => $this->entries,
            'groups' => $this->groups,
        ];

        return $this->view('mail.reminder_digest', $viewData)
            ->text('mail.reminder_digest_plain', $viewData);
    }
}
