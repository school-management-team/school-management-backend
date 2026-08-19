<?php

namespace App\Services;

use App\Models\Announcement;
use Carbon\Carbon;

/**
 * مرجع واحد للسؤال «هل هذا اليوم دوام؟» — بيستعمله الحضور والتعويض والجدول،
 * حتى ما ينرصد غياب أو ينعيّن بديل بيوم عطلة رسمية.
 */
class SchoolCalendarService
{
    // العطلة اللي بتغطي هذا التاريخ، أو null إذا ما في
    public function holidayOn(string $date): ?Announcement
    {
        return Announcement::holidays()->covering($date)->first();
    }

    public function isHoliday(string $date): bool
    {
        return $this->holidayOn($date) !== null;
    }

    // اليوم الأسبوعي بصيغة الجدول (sunday...thursday)، أو null لو جمعة/سبت
    public function schoolDayOf(string $date): ?string
    {
        $day = strtolower(Carbon::parse($date)->format('l'));

        if (in_array($day, config('school.school_days'))) {
            return $day;
        }

        return null;
    }

    public function isWeekend(string $date): bool
    {
        return $this->schoolDayOf($date) === null;
    }

    // يوم دوام فعلي: ضمن أيام الأسبوع الدراسي وما عليه عطلة رسمية
    public function isSchoolDay(string $date): bool
    {
        if ($this->isWeekend($date)) {
            return false;
        }

        return !$this->isHoliday($date);
    }

    /**
     * سبب توقّف الدوام بهذا اليوم، أو null إذا اليوم دوام عادي.
     * بترجّع رسالة جاهزة للعرض مع نوع السبب (weekend أو holiday).
     */
    public function nonSchoolDayReason(string $date): ?array
    {
        if ($this->isWeekend($date)) {
            return [
                'reason' => 'weekend',
                'message' => 'هذا التاريخ خارج أيام الدوام المدرسي',
            ];
        }

        $holiday = $this->holidayOn($date);

        if ($holiday === null) {
            return null;
        }

        return [
            'reason' => 'holiday',
            'message' => 'هذا التاريخ ضمن عطلة رسمية: '.$holiday->title,
            'holiday' => [
                'id' => $holiday->id,
                'title' => $holiday->title,
                'date' => $holiday->date->toDateString(),
                'end_date' => $holiday->end_date ? $holiday->end_date->toDateString() : null,
            ],
        ];
    }
}
