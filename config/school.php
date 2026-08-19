<?php

return [

    'student_number_ranges' => [
        1 => ['start' => 1000, 'end' => 1999, 'prefix' => '1'],
        2 => ['start' => 2000, 'end' => 2999, 'prefix' => '2'],
        3 => ['start' => 3000, 'end' => 3999, 'prefix' => '3'],
        4 => ['start' => 4000, 'end' => 4999, 'prefix' => '4'],
        5 => ['start' => 5000, 'end' => 5999, 'prefix' => '5'],
        6 => ['start' => 6000, 'end' => 6999, 'prefix' => '6'],
        7 => ['start' => 7000, 'end' => 7999, 'prefix' => '7'],
        8 => ['start' => 8000, 'end' => 8999, 'prefix' => '8'],
        9 => ['start' => 9000, 'end' => 9999, 'prefix' => '9'],
        10 => ['start' => 10000, 'end' => 10999, 'prefix' => '10'],
        11 => ['start' => 11000, 'end' => 11999, 'prefix' => '11'],
        12 => ['start' => 12000, 'end' => 12999, 'prefix' => '12'],
    ],

    'school_days' => ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday'],

    /** أسماء الأيام بالعربي — للرسائل الموجّهة للمستخدم */
    'day_labels' => [
        'sunday' => 'الأحد',
        'monday' => 'الإثنين',
        'tuesday' => 'الثلاثاء',
        'wednesday' => 'الأربعاء',
        'thursday' => 'الخميس',
        'friday' => 'الجمعة',
        'saturday' => 'السبت',
    ],

    'semesters' => [1, 2],

    /*
     | حالات الحضور. الطالب عندو حالة زيادة (خروج مبكر) لأنه بيغادر المدرسة
     | أثناء الدوام، والمعلم لأ. لازم تطابق enum العمود status بالجدولين.
     */
    'attendance_statuses' => [
        'student' => ['present', 'absent', 'late', 'excused', 'early_leave'],
        'teacher' => ['present', 'absent', 'late', 'excused'],
    ],

    /** الحالات اللي بتهم ولي الأمر (اللي مش حضور كامل) */
    'attendance_concerns' => ['absent', 'late', 'excused', 'early_leave'],

    /*
     | مكوّنات العلامة وأوزانها — مصدر واحد بدل ما تكون الأسماء والأوزان
     | مبعترة بين GradeService والكنترولرات والـ migration.
     | المفاتيح لازم تطابق enum العمود grades.type بالضبط.
     */
    'grade_components' => [
        'participation' => ['label' => 'المشاركة', 'weight' => 20],
        'quiz' => ['label' => 'الأعمال الفصلية', 'weight' => 30],
        'exam' => ['label' => 'الامتحان النهائي', 'weight' => 50],
    ],

    /*
     | تعريف الحصص وأوقاتها. الأوقات بتتقرأ من هون وقت إنشاء الجدول الأسبوعي،
     | حتى ما يصير اختلاف بتوقيت نفس الحصة بين معلم ومعلم.
     */
    'periods' => [
        1 => ['start' => '08:00:00', 'end' => '08:45:00', 'type' => 'class'],
        2 => ['start' => '08:45:00', 'end' => '09:30:00', 'type' => 'class'],
        3 => ['start' => '09:30:00', 'end' => '10:15:00', 'type' => 'class'],
        4 => ['start' => '10:15:00', 'end' => '10:35:00', 'type' => 'break'],
        5 => ['start' => '10:35:00', 'end' => '11:20:00', 'type' => 'class'],
        6 => ['start' => '11:20:00', 'end' => '12:05:00', 'type' => 'class'],
        7 => ['start' => '12:05:00', 'end' => '12:50:00', 'type' => 'class'],
        8 => ['start' => '12:50:00', 'end' => '13:35:00', 'type' => 'class'],
    ],
];