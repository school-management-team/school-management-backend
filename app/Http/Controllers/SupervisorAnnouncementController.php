<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Services\SchoolCalendarService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SupervisorAnnouncementController extends Controller
{
    const TYPES = ['academic', 'administrative', 'activity', 'holiday'];

    protected $calendarService;

    public function __construct(SchoolCalendarService $calendarService)
    {
        $this->calendarService = $calendarService;
    }

    
    public function index(Request $request)
    {
        $request->validate([
            'type' => 'sometimes|in:'.implode(',', self::TYPES),
            'is_important' => 'sometimes|boolean',
            'from' => 'sometimes|date_format:Y-m-d',
            'to' => 'sometimes|date_format:Y-m-d|after_or_equal:from',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $query = Announcement::with('supervisor.user:id,user_name');

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('is_important')) {
            $query->where('is_important', $request->boolean('is_important'));
        }

        if ($request->filled('from') && $request->filled('to')) {
            $query->overlapping($request->from, $request->to);
        }

        $page = $query->orderByDesc('date')->paginate($request->per_page ?? 10);

        $announcements = [];

        foreach ($page as $announcement) {
            $supervisor = $announcement->supervisor;

            $announcements[] = [
                'id' => $announcement->id,
                'title' => $announcement->title,
                'description' => $announcement->description,
                'type' => $announcement->type,
                'is_important' => $announcement->is_important,
                'date' => $announcement->date->toDateString(),
                'end_date' => $announcement->end_date ? $announcement->end_date->toDateString() : null,
                'days_count' => $announcement->days_count,
                'is_multi_day' => $announcement->is_multi_day,
                'image_path' => $announcement->image_path,
                'attachment_path' => $announcement->attachment_path,
                'published_by' => $supervisor && $supervisor->user ? $supervisor->user->user_name : null,
            ];
        }

        if ($page->total() === 0) {
            $message = $request->filled('type')
                ? 'لا توجد منشورات من هذا النوع'
                : 'لا توجد منشورات بعد';
        } else {
            $message = 'عدد المنشورات: '.$page->total();
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => [
                'announcements' => $announcements,
                'total' => $page->total(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
            ],
        ]);
    }

    public function holidays(Request $request)
    {
        $request->validate([
            'year' => 'sometimes|integer|min:2000|max:2100',
        ]);

        $year = $request->year ?? now()->year;

        $found = Announcement::holidays()
            ->overlapping($year.'-01-01', $year.'-12-31')
            ->orderBy('date')
            ->get();

        $holidays = [];
        $totalDays = 0;

        foreach ($found as $holiday) {
            $holidays[] = [
                'id' => $holiday->id,
                'title' => $holiday->title,
                'description' => $holiday->description,
                'date' => $holiday->date->toDateString(),
                'end_date' => $holiday->end_date ? $holiday->end_date->toDateString() : null,
                'days_count' => $holiday->days_count,
            ];

            $totalDays += $holiday->days_count;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'year' => $year,
                'holidays' => $holidays,
                'total_days' => $totalDays,
            ],
        ]);
    }


    public function dayStatus(Request $request)
    {
        $request->validate(['date' => 'sometimes|date_format:Y-m-d']);

        $date = $request->date ?? now()->toDateString();
        $reason = $this->calendarService->nonSchoolDayReason($date);

        $data = [
            'date' => $date,
            'is_school_day' => $reason === null,
            'day_of_week' => $this->calendarService->schoolDayOf($date),
        ];

    
        if ($reason) {
            $data = array_merge($data, $reason);
        }

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function show(Announcement $announcement)
    {
        return response()->json([
            'success' => true,
            'data' => $announcement->load('supervisor.user:id,user_name'),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validatePayload($request, false);

        $supervisor = $request->user()->supervisor;

        if (!$supervisor) {
            return response()->json([
                'success' => false,
                'message' => 'لا يوجد ملف موجّه مرتبط بهذا الحساب',
            ], 403);
        }

        $duplicate = Announcement::where('title', $validated['title'])
            ->where('type', $validated['type'])
            ->whereDate('date', $validated['date'])
            ->first();

        if ($duplicate) {
            return response()->json([
                'success' => false,
                'message' => "يوجد منشور بنفس العنوان والنوع والتاريخ (رقم {$duplicate->id}). عدّله بدل نشره من جديد",
                'data' => [
                    'existing_id' => $duplicate->id,
                    'title' => $duplicate->title,
                    'date' => $duplicate->date->toDateString(),
                ],
            ], 422);
        }

        $validated['supervisor_id'] = $supervisor->id;

        $validated = array_merge($validated, $this->uploads($request));

        $announcement = Announcement::create($validated);

        return response()->json([
            'success' => true,
            'message' => $this->createdMessage($announcement->type),
            'data' => $announcement->load('supervisor.user:id,user_name'),
        ], 201);
    }

    public function update(Request $request, Announcement $announcement)
    {
        $validated = $this->validatePayload($request, true);

        
        $hasFiles = $request->hasFile('image') || $request->hasFile('attachment');

        if (count($validated) === 0 && !$hasFiles) {
            return $this->nothingToUpdate();
        }

        $start = $validated['date'] ?? $announcement->date->toDateString();

        $end = $announcement->end_date ? $announcement->end_date->toDateString() : null;

        if (array_key_exists('end_date', $validated)) {
            $end = $validated['end_date'];
        }

        if ($end !== null && $end < $start) {
            return response()->json([
                'success' => false,
                'message' => 'تاريخ الانتهاء يجب أن يكون بعد تاريخ البداية أو مساوياً له',
            ], 422);
        }

        $uploads = $this->uploads($request);
  
        foreach ($uploads as $field => $newPath) {
            $this->deleteFile($announcement->$field);
        }

        $announcement->fill(array_merge($validated, $uploads));

    
        if (!$announcement->isDirty()) {
            return $this->noChangesMade($announcement->load('supervisor.user:id,user_name'));
        }

        $changed = array_keys($announcement->getDirty());
        $announcement->save();

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث المنشور بنجاح',
            'changed' => true,
            'changed_fields' => $changed,
            'data' => $announcement->fresh('supervisor.user:id,user_name'),
        ]);
    }

    public function destroy(Announcement $announcement)
    {
        $this->deleteFile($announcement->image_path);
        $this->deleteFile($announcement->attachment_path);

        $announcement->delete();

        return response()->json(['success' => true, 'message' => 'تم حذف المنشور بنجاح']);
    }

    private function validatePayload(Request $request, bool $updating): array
    {
        $required = 'required';

        if ($updating) {
            $required = 'sometimes';
        }

        $rules = [
            'title' => "{$required}|string|max:255",
            'description' => "{$required}|string",
            'type' => "{$required}|in:".implode(',', self::TYPES),
            'date' => "{$required}|date_format:Y-m-d",
            'is_important' => 'sometimes|boolean',
            'image' => 'sometimes|image|max:4096',
            'attachment' => 'sometimes|file|max:8192',
        ];

        
        if ($updating) {
            $rules['end_date'] = 'sometimes|nullable|date_format:Y-m-d';
        } else {
            $rules['end_date'] = 'sometimes|nullable|date_format:Y-m-d|after_or_equal:date';
        }

        $validated = $request->validate($rules);

        unset($validated['image'], $validated['attachment']);

        return $validated;
    }

    private function uploads(Request $request): array
    {
        $paths = [];

        if ($request->hasFile('image')) {
            $paths['image_path'] = $request->file('image')->store('announcements/images', 'public');
        }

        if ($request->hasFile('attachment')) {
            $paths['attachment_path'] = $request->file('attachment')->store('announcements/files', 'public');
        }

        return $paths;
    }

    private function deleteFile(?string $path): void
    {
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    private function createdMessage(string $type): string
    {
        if ($type === 'holiday') {
            return 'تم نشر العطلة الرسمية بنجاح';
        }

        if ($type === 'activity') {
            return 'تم نشر الفعالية بنجاح';
        }

        return 'تم نشر الإعلان بنجاح';
    }
}
