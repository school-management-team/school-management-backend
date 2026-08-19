<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * الأساس لكل إشعارات النظام.
 *
 * الفكرة: الكلاس الوارث ما بيهتم بالبنية ولا بالقنوات ولا بشكل الحمولة —
 * بس بيعرّف نوعه ورسالته وبياناته. الباقي بينحلّ من config/notifications.php.
 *
 * لإضافة نوع جديد: ضيفه بالكونفيغ، واعمل كلاس فيه type() و message() و payload().
 *
 * ShouldQueue: الإشعار بينحفظ وينبعت من الطابور حتى ما يبطّئ الطلب.
 * لو بدك بث فوري بدون طابور، بدّل ShouldQueue بـ ShouldBroadcastNow بالحدث.
 */
abstract class BaseNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** مفتاح النوع كما هو مسجّل في config/notifications.php */
    abstract public function type(): string;

    /** النص الظاهر للمستخدم */
    abstract public function message(): string;

    /** بيانات إضافية خاصة بالنوع (معرّفات، تواريخ، روابط) */
    abstract public function payload(): array;

    /** عنوان الإشعار — الافتراضي من الكونفيغ، والوارث بيقدر يتجاوزه */
    public function title(): string
    {
        return $this->config('title', 'إشعار');
    }

    /**
     * القنوات بتجي من الكونفيغ، فتغيير سلوك نوع كامل بيصير من مكان واحد
     * بدون ما نفتح كلاسات الإشعارات.
     */
    public function via(object $notifiable): array
    {
        return $this->config('channels', ['database']);
    }

    /** النسخة المحفوظة بقاعدة البيانات — نفس بنية النسخة المبثوثة */
    public function toArray(object $notifiable): array
    {
        return $this->body();
    }

    /** النسخة المبثوثة لحظياً عبر Reverb */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->body());
    }

    /**
     * اسم الحدث اللي بتسمعه الواجهة. موحّد لكل الأنواع، والتمييز بينهن
     * بيصير بحقل type داخل الحمولة — هيك الواجهة بتشترك بمستمع واحد بس.
     */
    public function broadcastType(): string
    {
        return config('notifications.event');
    }

    /**
     * البنية الموحّدة لكل إشعار — الواجهات بتعتمد عليها،
     * فأي نوع جديد بيوصلها بنفس الشكل بدون تغيير بالكود عندهن.
     */
    protected function body(): array
    {
        return [
            'type' => $this->type(),
            'title' => $this->title(),
            'message' => $this->message(),
            'icon' => $this->config('icon', 'bell'),
            'priority' => $this->config('priority', 'normal'),
            'data' => $this->payload(),
            'created_at' => now()->toIso8601String(),
        ];
    }

    /**
     * قراءة إعداد النوع الحالي.
     *
     * وصول مباشر للمصفوفة مش config('...types.'.$type.'.'.$key) — لأن
     * أسماء الأنواع فيها نقاط (class.announcement) ولارافيل بيفسّر النقطة
     * كمسار متداخل، فما بيلاقي المفتاح.
     */
    protected function config(string $key, $default = null)
    {
        $type = config('notifications.types')[$this->type()] ?? [];

        return $type[$key] ?? $default;
    }
}
