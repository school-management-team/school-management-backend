<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentFee extends Model
{
    protected $fillable = [
        'student_id', 'academic_year', 'total_amount', 'discount', 'note', 'created_by',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'discount' => 'decimal:2',
    ];

    protected $appends = ['net_amount', 'paid_amount', 'remaining_amount', 'is_settled'];

    public function student(): BelongsTo { return $this->belongsTo(Student::class); }
    public function payments(): HasMany { return $this->hasMany(FeePayment::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    public function scopeForYear($query, string $year) { return $query->where('academic_year', $year); }

    /** المستحق بعد الحسم */
    public function getNetAmountAttribute(): float
    {
        return round((float) $this->total_amount - (float) $this->discount, 2);
    }

    /** مجموع الدفعات — بيستعمل العلاقة المحمّلة إذا موجودة حتى ما نضرب الداتابيز مرة زيادة */
    public function getPaidAmountAttribute(): float
    {
        $sum = $this->relationLoaded('payments')
            ? $this->payments->sum('amount')
            : $this->payments()->sum('amount');

        return round((float) $sum, 2);
    }

    public function getRemainingAmountAttribute(): float
    {
        return round(max(0, $this->net_amount - $this->paid_amount), 2);
    }

    public function getIsSettledAttribute(): bool
    {
        return $this->remaining_amount <= 0;
    }
}
