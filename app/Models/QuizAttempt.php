<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuizAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'quiz_id',
        'user_id',
        'attempt_number',
        'started_at',
        'completed_at',
        'time_taken',
        'score',
        'total_points',
        'percentage',
        'status',
        'passed',
        'answers'
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'passed' => 'boolean',
        'answers' => 'array',
    ];

    /**
     * Get the quiz that owns the attempt.
     */
    public function quiz()
    {
        return $this->belongsTo(Quiz::class);
    }

    /**
     * Get the user that owns the attempt.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the answers for this attempt.
     */
    public function answers()
    {
        return $this->hasMany(QuizAnswer::class);
    }

    /**
     * Get the answer for a specific question.
     */
    public function getAnswerForQuestion($questionId)
    {
        return $this->answers()->where('quiz_question_id', $questionId)->first();
    }

    /**
     * Check if attempt is completed.
     */
    public function isCompleted()
    {
        return $this->status === 'completed';
    }

    /**
     * Check if attempt is in progress.
     */
    public function isInProgress()
    {
        return $this->status === 'in_progress';
    }

    /**
     * Check if attempt is abandoned.
     */
    public function isAbandoned()
    {
        return $this->status === 'abandoned';
    }

    /**
     * Get formatted time taken.
     */
    public function getFormattedTimeTakenAttribute()
    {
        if (!$this->time_taken) {
            return 'N/A';
        }

        $hours = floor($this->time_taken / 3600);
        $minutes = floor(($this->time_taken % 3600) / 60);
        $seconds = $this->time_taken % 60;

        if ($hours > 0) {
            return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
        }

        return sprintf('%02d:%02d', $minutes, $seconds);
    }

    /**
     * Get grade letter.
     */
    public function getGradeLetterAttribute()
    {
        if (!$this->percentage) {
            return 'N/A';
        }

        if ($this->percentage >= 90) return 'A';
        if ($this->percentage >= 80) return 'B';
        if ($this->percentage >= 70) return 'C';
        if ($this->percentage >= 60) return 'D';
        return 'F';
    }

    /**
     * Get grade color.
     */
    public function getGradeColorAttribute()
    {
        if (!$this->percentage) {
            return 'secondary';
        }

        if ($this->percentage >= 90) return 'success';
        if ($this->percentage >= 80) return 'info';
        if ($this->percentage >= 70) return 'warning';
        if ($this->percentage >= 60) return 'warning';
        return 'danger';
    }

    /**
     * Calculate score and percentage.
     */
    public function calculateScore()
    {
        $totalPoints = 0;
        $earnedPoints = 0;

        foreach ($this->answers as $answer) {
            $question = $answer->question;
            $totalPoints += $question->points;

            if ($answer->is_correct) {
                $earnedPoints += $question->points;
            }
        }

        $this->score = $earnedPoints;
        $this->total_points = $totalPoints;
        $this->percentage = $totalPoints > 0 ? round(($earnedPoints / $totalPoints) * 100, 2) : 0;
        $this->passed = $this->percentage >= $this->quiz->passing_score;

        $this->save();
    }

    /**
     * Complete the attempt.
     */
    public function complete()
    {
        $this->completed_at = now();
        $this->time_taken = $this->started_at->diffInSeconds($this->completed_at);
        $this->status = 'completed';
        $this->calculateScore();
        $this->save();
    }

    /**
     * Abandon the attempt.
     */
    public function abandon()
    {
        $this->completed_at = now();
        $this->time_taken = $this->started_at->diffInSeconds($this->completed_at);
        $this->status = 'abandoned';
        $this->save();
    }

    /**
     * Scope to get completed attempts.
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope to get attempts by user.
     */
    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to get attempts for a quiz.
     */
    public function scopeForQuiz($query, $quizId)
    {
        return $query->where('quiz_id', $quizId);
    }

    /**
     * Scope to get passed attempts.
     */
    public function scopePassed($query)
    {
        return $query->where('passed', true);
    }

    /**
     * Scope to get failed attempts.
     */
    public function scopeFailed($query)
    {
        return $query->where('passed', false);
    }
} 