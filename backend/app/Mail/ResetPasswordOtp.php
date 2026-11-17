<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ResetPasswordOtp extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $otp)
    {
    }

    public function build(): static
    {
        return $this->subject('Your Password Reset Verification Code')
            ->html("
                <div style='font-family: Arial, sans-serif; padding: 20px;'>
                    <h2>TechRon Reminder System</h2>
                    <p>You requested to reset your password.</p>
                    <h1 style='letter-spacing: 8px;'>{$this->otp}</h1>
                    <p>This code expires in 10 minutes.</p>
                    <p>If you did not request this, you can ignore this email.</p>
                </div>
            ");
    }
}
