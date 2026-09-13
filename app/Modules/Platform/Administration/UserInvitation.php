<?php

declare(strict_types=1);

namespace App\Modules\Platform\Administration;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Invitation to a new user: a password-reset token (tenant-keyed, see User::getEmailForPasswordReset) lets them set their first password. */
final class UserInvitation extends Notification
{
    use Queueable;

    public function __construct(private readonly string $token, private readonly string $organisation) {}

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function url(User $notifiable): string
    {
        return url(route('password.reset', ['token' => $this->token, 'email' => $notifiable->email], false));
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject("You have been invited to {$this->organisation}")
            ->greeting("Hello {$notifiable->name},")
            ->line("An administrator has created an account for you in {$this->organisation}.")
            ->action('Set your password', $this->url($notifiable))
            ->line('The link expires in '.config('auth.passwords.users.expire', 60).' minutes. Ask your administrator to send a new one if it has expired.');
    }
}
