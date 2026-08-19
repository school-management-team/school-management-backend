<?php

use App\Models\Guardian;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| صلاحيات قنوات البث
|--------------------------------------------------------------------------
|
| كل القنوات خاصة. الواجهة بتطلب /broadcasting/auth ومعها التوكن، ولارافيل
| بينفّذ الإغلاق المناسب. إذا رجع false، الاشتراك بينرفض ومحدا بيسمع شي.
|
| القاعدة العامة: ما حدا يشترك بقناة إلا إذا كان صاحبها فعلياً.
|
*/

/**
 * القناة الشخصية — عليها بتوصل كل إشعارات المستخدم.
 * الشرط: الرقم بالقناة هو رقم المستخدم المسجّل دخوله نفسه.
 */
Broadcast::channel('user.{id}', function (User $user, int $id) {
    return $user->id === $id && $user->status === 'active';
});

/**
 * قناة الشعبة — بثّ جماعي لطلاب شعبة (بديل عن إرسال فردي لما العدد كبير).
 *
 * المسموح لهم:
 *   - طالب فعلاً بهالشعبة
 *   - معلم مكلّف بتدريس هالشعبة
 *   - ولي أمر عندو ابن بهالشعبة
 *   - الموجّه والأدمن (إشراف عام)
 */
Broadcast::channel('section.{sectionId}', function (User $user, int $sectionId) {
    if ($user->status !== 'active') {
        return false;
    }

    if (in_array($user->role, ['supervisor', 'admin'])) {
        return true;
    }

    if ($user->role === 'student') {
        return Student::where('user_id', $user->id)
            ->where('section_id', $sectionId)
            ->exists();
    }

    if ($user->role === 'teacher') {
        return Teacher::where('user_id', $user->id)
            ->whereHas('assignments', function ($assignment) use ($sectionId) {
                $assignment->where('section_id', $sectionId);
            })
            ->exists();
    }

    if ($user->role === 'guardian') {
        return Guardian::where('user_id', $user->id)
            ->whereHas('students', function ($student) use ($sectionId) {
                $student->where('section_id', $sectionId);
            })
            ->exists();
    }

    return false;
});

/**
 * قناة الدور — إعلانات لكل من يحمل دوراً معيّناً (كل المعلمين مثلاً).
 * الشرط: دور المستخدم هو نفسه المطلوب.
 */
Broadcast::channel('role.{role}', function (User $user, string $role) {
    return $user->status === 'active' && $user->role === $role;
});
