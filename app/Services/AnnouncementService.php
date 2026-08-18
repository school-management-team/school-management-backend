<?php
namespace App\Services;

use App\Models\Announcement;

class AnnouncementService
{
    // كل الإعلانات مع الفلاتر (الكل / إداري / أكاديمي / نشاطات + مهم)
    public function list(array $filters)
    {
        $query = Announcement::query();

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (array_key_exists('is_important', $filters)) {
            $query->where('is_important', $filters['is_important']);
        }

        return $query->with('supervisor.user:id,user_name')
            ->orderBy('date', 'desc')
            ->paginate($filters['per_page'] ?? 10);
    }

    // الفعاليات القادمة — لم ينتهِ تاريخها، مع فلترة اختيارية بالنوع
    public function upcoming(int $limit = 5, ?string $type = null)
    {
        $query = Announcement::where('date', '>=', now()->toDateString());

        if ($type) {
            $query->where('type', $type);
        }

        return $query->orderBy('date', 'asc')->limit($limit)->get();
    }

    // أحدث تاريخين هامّين لم ينتهيا (لبطاقة "تواريخ هامة" بالتقويم)
    public function upcomingImportant(int $limit = 2)
    {
        return Announcement::where('date', '>=', now()->toDateString())
            ->where('is_important', true)
            ->orderBy('date', 'asc')
            ->limit($limit)
            ->get();
    }

    // نقاط التقويم (أي يوم فيه إعلان بشهر معين)
    public function calendarDots(int $year, int $month)
    {
        return Announcement::whereYear('date', $year)
            ->whereMonth('date', $month)
            ->pluck('date')
            ->unique()
            ->values();
    }

    // كل إعلانات شهر كامل (العرض الافتراضي تحت التقويم)
    public function forMonth(int $year, int $month)
    {
        return Announcement::whereYear('date', $year)
            ->whereMonth('date', $month)
            ->orderBy('date', 'asc')
            ->get();
    }

    // كل إعلانات يوم محدد (لما المستخدم يضغط على يوم بالتقويم)
    public function forDay(int $year, int $month, int $day)
    {
        return Announcement::whereYear('date', $year)
            ->whereMonth('date', $month)
            ->whereDay('date', $day)
            ->orderBy('date', 'desc')
            ->get();
    }

    // إعلان واحد بالتفصيل
    public function find(int $id): Announcement
    {
        return Announcement::with('supervisor.user:id,user_name')->findOrFail($id);
    }
}
