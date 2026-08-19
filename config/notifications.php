<?php

return [

    /*
    |---------------------------------------------------------------------------
    | أنواع الإشعارات
    |---------------------------------------------------------------------------
    |
    | مصدر واحد لكل نوع إشعار بالنظام. لإضافة نوع جديد بالمستقبل:
    |   1. ضيف سطر هون
    |   2. اعمل كلاس بيرث BaseNotification
    | ما في داعي تعدّل الكنترولرات ولا القنوات ولا منطق التوجيه.
    |
    | key      : المعرّف اللي بيوصل للواجهات بحقل "type"
    | title    : عنوان افتراضي (الكلاس بيقدر يتجاوزه)
    | icon     : تلميح للواجهة لاختيار الأيقونة
    | priority : normal | high — الواجهة بتقرر إذا تعمل صوت/اهتزاز
    | channels : قنوات لارافيل المستعملة (database للأرشيف، broadcast للحظة)
    |
    */
    'types' => [

        // ---------- من المعلم إلى طلاب شعبته ----------
        'assignment.published' => [
            'title' => 'واجب جديد',
            'icon' => 'assignment',
            'priority' => 'high',
            'channels' => ['database', 'broadcast'],
        ],

        'class.announcement' => [
            'title' => 'إعلان من المعلم',
            'icon' => 'megaphone',
            'priority' => 'normal',
            'channels' => ['database', 'broadcast'],
        ],

        // ---------- من الموجّه إلى المعلمين وأولياء الأمور ----------
        'student.academic_drop' => [
            'title' => 'تنبيه تراجع دراسي',
            'icon' => 'trending-down',
            'priority' => 'high',
            'channels' => ['database', 'broadcast'],
        ],

        'meeting.scheduled' => [
            'title' => 'موعد اجتماع',
            'icon' => 'calendar',
            'priority' => 'high',
            'channels' => ['database', 'broadcast'],
        ],

        // ---------- إشعارات عامة موجودة أصلاً بالنظام ----------
        'school.announcement' => [
            'title' => 'إعلان مدرسي',
            'icon' => 'megaphone',
            'priority' => 'normal',
            'channels' => ['database', 'broadcast'],
        ],

        'substitution.assigned' => [
            'title' => 'تكليف بتعويض حصة',
            'icon' => 'swap',
            'priority' => 'high',
            'channels' => ['database', 'broadcast'],
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | أسماء القنوات
    |---------------------------------------------------------------------------
    |
    | كل القنوات خاصة (private). الصيغة موحّدة هون حتى ما تنكتب يدوياً
    | بأماكن متفرقة وتصير عرضة للأخطاء المطبعية.
    |
    */
    'channels' => [
        'user' => 'user.{id}',            // إشعارات شخص واحد
        'section' => 'section.{id}',      // كل طلاب شعبة
        'role' => 'role.{role}',          // كل من يحمل دوراً معيناً
    ],

    // اسم الحدث اللي بتسمعه الواجهات على قناة المستخدم
    'event' => 'notification.created',
];
