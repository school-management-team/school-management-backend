<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Question extends Model
{
    protected $fillable = [
        'teacher_id', 'subject_id','class_id', 'type', 'difficulty',
        'text', 'choices', 'model_answer', 'usage_count',
    ];

    protected $casts = ['choices' => 'array'];

    public function teacher(): BelongsTo { return $this->belongsTo(Teacher::class); }
    public function subject(): BelongsTo { return $this->belongsTo(Subject::class); }
    public function schoolClass(): BelongsTo { return $this->belongsTo(SchoolClass::class, 'class_id'); }


    public function getCorrectChoicesAttribute(): array
    {
        $correct = [];
        foreach ($this->choices ?? [] as $choice) {
            if (!empty($choice['is_correct'])) {
                $correct[] = $choice;
            }
        }
        return $correct;
    }
}
