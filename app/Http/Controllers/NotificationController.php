<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * إشعارات المستخدم المسجّل دخوله — لكل الأدوار.
 *
 * البث اللحظي بيوصل الإشعار وقت وقوعه، وهدول النقاط للأرشيف:
 * فتح التطبيق، تصفّح القديم، وعدّاد غير المقروء.
 *
 * كل نقطة بتشتغل على إشعارات صاحب التوكن فقط — ما في وصول لإشعارات غيره.
 */
class NotificationController extends Controller
{
    /**
     * قائمة مقسّمة لصفحات.
     * GET /api/notifications?only_unread=1&type=assignment.published&per_page=20
     */
    public function index(Request $request)
    {
        $request->validate([
            'only_unread' => 'sometimes|boolean',
            'type' => 'sometimes|string|max:80',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $query = $request->user()->notifications();

        if ($request->boolean('only_unread')) {
            $query->whereNull('read_at');
        }

        // الفلترة بالنوع بتصير على حقل type داخل الحمولة المخزّنة
        if ($request->filled('type')) {
            $query->where('data->type', $request->type);
        }

        $page = $query->paginate($request->per_page ?? 20);

        $notifications = [];

        foreach ($page as $notification) {
            $notifications[] = $this->present($notification);
        }

        $unread = $request->user()->unreadNotifications()->count();

        if ($page->total() === 0) {
            $message = $request->boolean('only_unread')
                ? 'لا توجد إشعارات غير مقروءة'
                : 'لا توجد إشعارات بعد';
        } else {
            $message = 'عدد الإشعارات: '.$page->total();
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => [
                'notifications' => $notifications,
                'unread_count' => $unread,
                'total' => $page->total(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
            ],
        ]);
    }

    /**
     * عدّاد غير المقروء — خفيف، للشارة الحمرا فوق الجرس.
     * GET /api/notifications/unread-count
     */
    public function unreadCount(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => ['unread_count' => $request->user()->unreadNotifications()->count()],
        ]);
    }

    /**
     * تعليم إشعار واحد كمقروء.
     * PATCH /api/notifications/{id}/read
     */
    public function markAsRead(Request $request, string $id)
    {
        $notification = $request->user()->notifications()->find($id);

        if (!$notification) {
            return response()->json([
                'success' => false,
                'message' => 'لا يوجد إشعار بهذا الرقم ضمن إشعاراتك',
            ], 404);
        }

        // المقروء مسبقاً مش خطأ — بس ما بصير نقول "تم التعليم" ونحنا ما غيّرنا شي
        if ($notification->read_at !== null) {
            return response()->json([
                'success' => true,
                'changed' => false,
                'message' => 'هذا الإشعار مقروء مسبقاً',
                'data' => $this->present($notification),
            ]);
        }

        $notification->markAsRead();

        return response()->json([
            'success' => true,
            'changed' => true,
            'message' => 'تم تعليم الإشعار كمقروء',
            'data' => [
                'notification' => $this->present($notification->fresh()),
                'unread_count' => $request->user()->unreadNotifications()->count(),
            ],
        ]);
    }

    /**
     * تعليم كل غير المقروء.
     * PATCH /api/notifications/read-all
     */
    public function markAllAsRead(Request $request)
    {
        $count = $request->user()->unreadNotifications()->count();

        if ($count === 0) {
            return response()->json([
                'success' => true,
                'changed' => false,
                'message' => 'لا توجد إشعارات غير مقروءة',
                'data' => ['marked_count' => 0, 'unread_count' => 0],
            ]);
        }

        $request->user()->unreadNotifications->markAsRead();

        return response()->json([
            'success' => true,
            'changed' => true,
            'message' => "تم تعليم {$count} إشعار كمقروء",
            'data' => ['marked_count' => $count, 'unread_count' => 0],
        ]);
    }

    /**
     * حذف إشعار من الأرشيف.
     * DELETE /api/notifications/{id}
     */
    public function destroy(Request $request, string $id)
    {
        $notification = $request->user()->notifications()->find($id);

        if (!$notification) {
            return response()->json([
                'success' => false,
                'message' => 'لا يوجد إشعار بهذا الرقم ضمن إشعاراتك',
            ], 404);
        }

        $notification->delete();

        return response()->json([
            'success' => true,
            'message' => 'تم حذف الإشعار',
            'data' => ['unread_count' => $request->user()->unreadNotifications()->count()],
        ]);
    }

    /**
     * شكل موحّد للإشعار — نفس بنية الحمولة المبثوثة، زائد id وحالة القراءة.
     * هيك الواجهة بتعرض الإشعار الواصل لحظياً والمجلوب من الأرشيف بنفس الكود.
     */
    private function present($notification): array
    {
        $data = $notification->data;

        return [
            'id' => $notification->id,
            'type' => $data['type'] ?? null,
            'title' => $data['title'] ?? null,
            'message' => $data['message'] ?? null,
            'icon' => $data['icon'] ?? 'bell',
            'priority' => $data['priority'] ?? 'normal',
            'data' => $data['data'] ?? [],
            'is_read' => $notification->read_at !== null,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at->toIso8601String(),
        ];
    }
}
