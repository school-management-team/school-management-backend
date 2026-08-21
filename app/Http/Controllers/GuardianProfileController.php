<?php

namespace App\Http\Controllers;

use App\Services\AuthService;
use Illuminate\Http\Request;


class GuardianProfileController extends Controller
{
    protected $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * عرض البروفايل.
     * GET /api/guardian/profile
     */
    public function show(Request $request)
    {
        $user = $request->user();
        $guardian = $user->guardian;

        if (!$guardian) {
            return $this->noGuardianProfile();
        }

        $data = $this->authService->formatUser($user);

        // formatUser بترجّع قائمة الأولاد كمان — مالها محل هون،
        // إلها نقطتها الخاصة GET /api/guardian/children
        unset($data['profile']['students']);

        $data['profile']['relationship_label'] = $this->relationshipLabel($guardian->relationship);
        $data['profile']['number_of_children'] = $guardian->number_of_children;
        $data['profile']['joined_at'] = $guardian->created_at->toDateString();

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * تعديل البروفايل — الحقول الشخصية وحقول ولي الأمر بنفس الطلب.
     * PUT /api/guardian/profile
     */
    public function update(Request $request)
    {
        $user = $request->user();
        $guardian = $user->guardian;

        if (!$guardian) {
            return $this->noGuardianProfile();
        }

        $validated = $request->validate([
            'user_name' => 'sometimes|string|min:3|max:100',
            'phone' => 'sometimes|string|regex:/^09[0-9]{8}$/|unique:users,phone,'.$user->id,
            'gender' => 'sometimes|in:male,female',
            'birth_date' => 'sometimes|date_format:Y-m-d|before:today',
            'relationship' => 'sometimes|in:father,mother',
            'number_of_children' => 'sometimes|integer|min:1|max:20',
        ], $this->messages());

        if (count($validated) === 0) {
            return $this->nothingToUpdate();
        }

        // توزيع الحقول على جدولها — الواجهة بتبعت فورم واحد وما بتهتم بالتقسيم
        $userFields = ['user_name', 'phone', 'gender', 'birth_date'];

        $userData = [];
        $guardianData = [];

        foreach ($validated as $field => $value) {
            if (in_array($field, $userFields)) {
                $userData[$field] = $value;
            } else {
                $guardianData[$field] = $value;
            }
        }

        $user->fill($userData);
        $guardian->fill($guardianData);

        // شو تغيّر فعلياً؟ لو بعت نفس القيم المحفوظة، ما منكذب ومنقول "تم التعديل"
        $changed = array_merge(
            array_keys($user->getDirty()),
            array_keys($guardian->getDirty())
        );

        if (count($changed) === 0) {
            return $this->noChangesMade($this->cleanProfile($user, $guardian));
        }

        $user->save();
        $guardian->save();

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث البيانات',
            'changed' => true,
            'changed_fields' => $changed,
            'data' => $this->cleanProfile($user->fresh(), $guardian->fresh()),
        ]);
    }

    /** نفس شكل الخرج المستعمل بـ show، حتى الواجهة تتعامل مع بنية وحدة */
    private function cleanProfile($user, $guardian)
    {
        $data = $this->authService->formatUser($user);

        unset($data['profile']['students']);

        $data['profile']['relationship_label'] = $this->relationshipLabel($guardian->relationship);
        $data['profile']['number_of_children'] = $guardian->number_of_children;
        $data['profile']['joined_at'] = $guardian->created_at->toDateString();

        return $data;
    }

    /** الإنجليزي للمنطق البرمجي، والعربي للعرض */
    private function relationshipLabel($relationship)
    {
        $labels = [
            'father' => 'الأب',
            'mother' => 'الأم',
        ];

        return $labels[$relationship] ?? $relationship;
    }

    private function messages()
    {
        return [
            'phone.regex' => 'رقم الهاتف يجب أن يبدأ بـ 09 ويتكوّن من 10 أرقام',
            'phone.unique' => 'رقم الهاتف مستخدم من حساب آخر',
            'birth_date.date_format' => 'تاريخ الميلاد يجب أن يكون بصيغة YYYY-MM-DD',
            'birth_date.before' => 'تاريخ الميلاد يجب أن يكون في الماضي',
            'relationship.in' => 'صلة القرابة يجب أن تكون father أو mother',
            'number_of_children.min' => 'عدد الأولاد يجب أن يكون 1 على الأقل',
        ];
    }

    private function noGuardianProfile()
    {
        return response()->json([
            'success' => false,
            'message' => 'هذا الحساب غير مرتبط ببيانات ولي أمر',
        ], 403);
    }
}
