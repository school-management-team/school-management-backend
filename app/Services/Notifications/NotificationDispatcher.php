<?php

namespace App\Services\Notifications;

use App\Notifications\BaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/**
 * نقطة الإرسال الوحيدة بالنظام.
 *
 * الكنترولر بيقول: ابعت هالإشعار لهدول. وهاد الكلاس بيتكفّل بالباقي —
 * التحقق من النوع، تجاهل القوائم الفاضية، وإرجاع عدد من وصلهم الإشعار.
 *
 * فايدة وجوده: لو بدنا بكرا نضيف تسجيل (logging) أو حدّ أقصى للإرسال
 * أو تجميع الإشعارات، بينتعدّل هون بس.
 */
class NotificationDispatcher
{
    public function __construct(protected RecipientResolver $recipients) {}

    /** الوصول للمُحلّل من غير حقن ثاني */
    public function to(): RecipientResolver
    {
        return $this->recipients;
    }

    /**
     * الإرسال الفعلي.
     * بيرجّع عدد المستلمين — مفيد للرد على الكنترولر ("وصل 24 طالب").
     */
    public function send(Collection $users, BaseNotification $notification): int
    {
        $this->guardType($notification);

        if ($users->isEmpty()) {
            return 0;
        }

        NotificationFacade::send($users, $notification);

        return $users->count();
    }

    /** إرسال لمستخدم واحد */
    public function sendTo($user, BaseNotification $notification): int
    {
        if (!$user) {
            return 0;
        }

        return $this->send(collect([$user]), $notification);
    }

    /**
     * النوع لازم يكون مسجّل بالكونفيغ.
     * بدون هالفحص، خطأ مطبعي بمفتاح النوع بيمرق صامت وبيوصل للواجهة
     * إشعار بلا عنوان ولا أيقونة ولا أولوية.
     */
    protected function guardType(BaseNotification $notification): void
    {
        $type = $notification->type();

        // وصول مباشر — أسماء الأنواع فيها نقاط فما بتنفع مسارات النقاط
        if (!array_key_exists($type, config('notifications.types', []))) {
            throw new \InvalidArgumentException(
                "نوع الإشعار [{$type}] غير مسجّل في config/notifications.php"
            );
        }
    }
}
