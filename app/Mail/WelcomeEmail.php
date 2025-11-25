<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\User;

class WelcomeEmail extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $role;

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->role = ucfirst($user->role);
    }

    public function build()
    {
        return $this->subject("Hoş Geldiniz! - {$this->role} Hesabınız Aktif")
                    ->view('emails.welcome');
    }
} 