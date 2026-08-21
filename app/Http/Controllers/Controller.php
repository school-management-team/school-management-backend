<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

abstract class Controller
{
    /**
     * ردّ موحّد لما يوصل طلب تعديل بدون أي حقل.
     *
     * كل حقول التعديل عادة 'sometimes'، يعني الـ body الفاضي بيمرق الفاليديشن
     * و update([]) ما بيعمل شي — بس الرد بيقول "تم التعديل بنجاح" وهاد بيضلّل.
     */
    protected function nothingToUpdate()
    {
        return response()->json([
            'success' => false,
            'message' => 'لم تُرسل أي بيانات للتعديل',
        ], 422);
    }

    /**
     * ردّ موحّد لما تكون القيم المرسلة مطابقة للمحفوظة.
     *
     * مش خطأ — الطلب سليم والحالة صارت متل ما بدك. بس ما بصير نقول
     * "تم التعديل" ونحنا ما كتبنا ولا حقل.
     */
    protected function noChangesMade($data = null)
    {
        return response()->json([
            'success' => true,
            'message' => 'لم يطرأ أي تغيير — القيم المرسلة مطابقة للمحفوظة',
            'changed' => false,
            'data' => $data,
        ]);
    }

    /**
     * تأكيد بإعادة الطلب.
     *
     * أول طلب حذف بيرجّع تحذير (409) وبيسجّل إنو المستخدم شاف التحذير.
     * لو أعاد نفس الطلب خلال المهلة، بينفّذ الحذف مباشرة.
     *
     * أوضح من باراميتر force: المستخدم بيقرأ التحذير وبيضغط "حذف" مرة تانية،
     * بدون ما يعدّل الرابط.
     *
     * المفتاح فيه رقم المستخدم، فتأكيد موجّه ما بيخدم موجّه تاني.
     */
    protected function awaitingConfirmation(Request $request, string $action, $id): bool
    {
        $key = $this->confirmationKey($request, $action, $id);

        if (Cache::pull($key) !== null) {
            return false;   // شاف التحذير وأعاد الطلب — ننفّذ
        }

        Cache::put($key, true, now()->addMinutes(5));

        return true;        // أول مرة — نعرض التحذير
    }

    /** إلغاء تأكيد معلّق (بعد تنفيذ العملية أو إلغائها) */
    protected function clearConfirmation(Request $request, string $action, $id): void
    {
        Cache::forget($this->confirmationKey($request, $action, $id));
    }

    private function confirmationKey(Request $request, string $action, $id): string
    {
        $userId = $request->user() ? $request->user()->id : 'guest';

        return "confirm:{$action}:{$userId}:{$id}";
    }
}
