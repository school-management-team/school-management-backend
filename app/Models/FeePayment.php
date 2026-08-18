<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeePayment extends Model
{
    protected $fillable = [
        'student_fee_id', 'amount', 'paid_at', 'method', 'receipt_number', 'note', 'recorded_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'date',
    ];

    /** بلا وقت — العمود DATE */
    public function setPaidAtAttribute($value)
    {
        $this->attributes['paid_at'] = Carbon::parse($value)->toDateString();
    }

    public function studentFee(): BelongsTo { return $this->belongsTo(StudentFee::class); }
    public function recorder(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by'); }
}
