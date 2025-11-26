<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\User;
use App\Models\Course;
use App\Models\Order;

class CourseEnrollmentEmail extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $course;
    public $order;

    public function __construct(User $user, Course $course, Order $order)
    {
        $this->user = $user;
        $this->course = $course;
        $this->order = $order;
    }

    public function build()
    {
        return $this->subject("Kurs Kaydınız Tamamlandı: {$this->course->title}")
                    ->view('emails.course-enrollment');
    }
} 