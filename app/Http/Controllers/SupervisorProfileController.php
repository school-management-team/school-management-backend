<?php

namespace App\Http\Controllers;

use App\Models\Supervisor;
use App\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * بروفايل الموجّه.
 *
 * البيانات موزّعة على جدولين:
 *   users       → الاسم، الإيميل، الهاتف، الجنس، تاريخ الميلاد، الصورة
 *   supervisors → المؤهل، الاختصاص، النبذة، السيرة الذاتية
 *
 * الموجّه بيشوف ويعدّل ملفه هو فقط — ما في وصول لملفات غيره.
 */
class SupervisorProfileController extends Controller
{
    /** المؤهلات المسموحة — enum بالداتابيز */
    const QUALIFICATIONS = ['bachelor', 'master', 'doctorate'];

    protected $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * عرض البروفايل: معلومات شخصية + مهنية + ملخّص نشاط.
     * GET /api/supervisor/profile
     */
    public function show(Request $request)
    {
        $supervisor = $request->user()->supervisor;

        if (!$supervisor) {
            return $this->noSupervisorProfile();
        }

        // formatUser بترجّع البنية الموحّدة لكل الأدوار
        $data = $this->authService->formatUser($request->user());

        // منكمّل الحقول الخاصة بالموجّه اللي مش موجودة بالبنية العامة
        $data['profile']['qualification_label'] = $this->qualificationLabel($supervisor->educational_qualification);
        $data['profile']['cv_file_name'] = basename($supervisor->cv_file);
        $data['profile']['joined_at'] = $supervisor->created_at->toDateString();

        $data['activity'] = $this->activitySummary($supervisor);

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * تعديل البروفايل — الحقول الشخصية والمهنية معاً.
     * PUT /api/supervisor/profile
     *
     * الإيميل وكلمة السر مش هون: الأول بدّو تحقق، والتانية إلها نقطة خاصة.
     */
    public function update(Request $request)
    {
        $supervisor = $request->user()->supervisor;

        if (!$supervisor) {
            return $this->noSupervisorProfile();
        }

        $validated = $request->validate([
            'user_name' => 'sometimes|string|min:3|max:255',
            'phone' => 'sometimes|string|regex:/^09[0-9]{8}$/|unique:users,phone,'.$request->user()->id,
            'gender' => 'sometimes|in:male,female',
            'birth_date' => 'sometimes|date_format:Y-m-d|before:today',
            'educational_qualification' => 'sometimes|in:'.implode(',', self::QUALIFICATIONS),
            'specialization' => 'sometimes|string|min:2|max:255',
            'bio' => 'sometimes|string|max:2000',
        ]);

        if (count($validated) === 0) {
            return $this->nothingToUpdate();
        }

        // منفصل الحقول حسب الجدول اللي بتنتمي إله
        $userFields = ['user_name', 'phone', 'gender', 'birth_date'];

        $userData = [];
        $supervisorData = [];

        foreach ($validated as $field => $value) {
            if (in_array($field, $userFields)) {
                $userData[$field] = $value;
            } else {
                $supervisorData[$field] = $value;
            }
        }

        $user = $request->user();
        $user->fill($userData);
        $supervisor->fill($supervisorData);

        $changed = array_merge(
            array_keys($user->getDirty()),
            array_keys($supervisor->getDirty())
        );

        // القيم المرسلة مطابقة للمحفوظة — ما في شي ننفّذه
        if (count($changed) === 0) {
            return $this->noChangesMade($this->authService->formatUser($user));
        }

        $user->save();
        $supervisor->save();

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث الملف الشخصي بنجاح',
            'changed' => true,
            'changed_fields' => $changed,
            'data' => $this->authService->formatUser($user->fresh()),
        ]);
    }

    /**
     * استبدال السيرة الذاتية.
     * POST /api/supervisor/profile/cv   (multipart/form-data)
     *
     * دايماً استبدال مش رفع أول: العمود cv_file إلزامي بالداتابيز والتسجيل
     * بيفرض رفع سيرة، فما في موجّه بلا سيرة.
     */
    public function uploadCv(Request $request)
    {
        $supervisor = $request->user()->supervisor;

        if (!$supervisor) {
            return $this->noSupervisorProfile();
        }

        $request->validate([
            'cv_file' => 'required|file|mimes:pdf,doc,docx|max:8192',
        ]);

        $previous = $supervisor->cv_file;

        // القرص local مش public — السيرة الذاتية ما بتتصفّح من الرابط مباشرة
        $path = $request->file('cv_file')->store('supervisors/cv', 'local');

        $supervisor->update(['cv_file' => $path]);

        // منحذف القديم بعد نجاح الرفع، حتى ما يتراكم على القرص
        if ($previous && $previous !== $path && Storage::disk('local')->exists($previous)) {
            Storage::disk('local')->delete($previous);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث السيرة الذاتية',
            'data' => [
                'file_name' => basename($path),
                'previous_file_name' => $previous ? basename($previous) : null,
            ],
        ]);
    }

    /**
     * تحميل السيرة الذاتية الخاصة بالموجّه نفسه.
     * GET /api/supervisor/profile/cv
     */
    public function downloadCv(Request $request)
    {
        $supervisor = $request->user()->supervisor;

        if (!$supervisor) {
            return $this->noSupervisorProfile();
        }

        // الملف مسجّل بالداتابيز بس مفقود من القرص — بيصير لو انمسح يدوياً
        if (!Storage::disk('local')->exists($supervisor->cv_file)) {
            return response()->json([
                'success' => false,
                'message' => 'ملف السيرة الذاتية مفقود من التخزين، أعد رفعه',
            ], 404);
        }

        return response()->download(Storage::disk('local')->path($supervisor->cv_file));
    }

    // ==================== أدوات داخلية ====================

    /** ملخّص نشاط الموجّه — كم عمل بالنظام */
    private function activitySummary(Supervisor $supervisor): array
    {
        return [
            'announcements' => $supervisor->announcements()->count(),
            'student_attendance_records' => $supervisor->recordedAttendances()->count(),
            'teacher_attendance_records' => $supervisor->recordedTeacherAttendances()->count(),
            'substitutions_assigned' => $supervisor->assignedSubstitutions()->count(),
        ];
    }

    /** اسم المؤهل بالعربي للعرض */
    private function qualificationLabel(?string $qualification): ?string
    {
        if ($qualification === null) {
            return null;
        }

        $labels = [
            'bachelor' => 'إجازة جامعية',
            'master' => 'ماجستير',
            'doctorate' => 'دكتوراه',
        ];

        return $labels[$qualification] ?? $qualification;
    }

    private function noSupervisorProfile()
    {
        return response()->json([
            'success' => false,
            'message' => 'لا يوجد ملف موجّه مرتبط بهذا الحساب',
        ], 403);
    }
}
