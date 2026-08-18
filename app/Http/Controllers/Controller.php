<?php

namespace App\Http\Controllers;

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
}
