<?php

namespace App\Services;

use App\Models\Announcement;

class AnnouncementService
{
    // كل الإعلانات مع الفلاتر (الكل / إداري / أكاديمي / نشاطات)
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

    // الفعاليات القادمة
    public function upcoming(int $limit = 5)
    {
        return Announcement::where('date', '>=', now()->toDateString())
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

    // إعلان واحد بالتفصيل
    public function find(int $id): Announcement
    {
        return Announcement::with('supervisor.user:id,user_name')->findOrFail($id);
    }
}
