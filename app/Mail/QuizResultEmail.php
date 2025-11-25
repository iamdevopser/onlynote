<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\User;
use App\Models\Quiz;
use App\Models\QuizAttempt;

class QuizResultEmail extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $quiz;
    public $attempt;

    public function __construct(User $user, Quiz $quiz, QuizAttempt $attempt)
    {
        $this->user = $user;
        $this->quiz = $quiz;
        $this->attempt = $attempt;
    }

    public function build()
    {
        $result = $this->attempt->passed ? 'Başarılı' : 'Başarısız';
        return $this->subject("Quiz Sonucu: {$this->quiz->title} - {$result}")
                    ->view('emails.quiz-result');
    }
} 