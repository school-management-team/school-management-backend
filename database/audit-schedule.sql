-- ═══════════════════════════════════════════════════════════════
--  فحص تناسق التكليفات والجدول
--
--  الاستعمال: phpMyAdmin ← اختر قاعدة البيانات ← تبويب SQL ← الصق
--             استعلام واحد كل مرة ← Go
--
--  القسم الأول كله SELECT — بيقرأ وما بيعدّل شي.
--  الحذف بالقسم الأخير، وما تشغّله قبل ما تقرأ التحذير.
-- ═══════════════════════════════════════════════════════════════


-- ───────────────────────────────────────────────────────────────
-- ١. تكليفات خارج مرحلة المعلم
--    مثال: أستاذ ابتدائي مكلّف بصف ثانوي (حصة الفيزياء بأول ثانوي)
-- ───────────────────────────────────────────────────────────────
SELECT
    a.id            AS assignment_id,
    u.user_name     AS teacher,
    ts.name         AS teacher_stage,
    c.name          AS class_name,
    cs.name         AS class_stage,
    sub.name        AS subject
FROM teacher_assignments a
JOIN teachers t    ON t.id  = a.teacher_id
JOIN users    u    ON u.id  = t.user_id
JOIN stages   ts   ON ts.id = t.stage_id
JOIN sections s    ON s.id  = a.section_id
JOIN classes  c    ON c.id  = s.class_id
JOIN stages   cs   ON cs.id = c.stage_id
JOIN subjects sub  ON sub.id = a.subject_id
WHERE c.stage_id <> t.stage_id;


-- ───────────────────────────────────────────────────────────────
-- ٢. مادة غير مدرَّسة في مرحلة الصف
--    مثال: التاريخ بالابتدائي — الابتدائي أصلاً ما فيه تاريخ
-- ───────────────────────────────────────────────────────────────
SELECT
    a.id            AS assignment_id,
    u.user_name     AS teacher,
    sub.name        AS subject,
    c.name          AS class_name,
    cs.name         AS class_stage
FROM teacher_assignments a
JOIN teachers t    ON t.id  = a.teacher_id
JOIN users    u    ON u.id  = t.user_id
JOIN sections s    ON s.id  = a.section_id
JOIN classes  c    ON c.id  = s.class_id
JOIN stages   cs   ON cs.id = c.stage_id
JOIN subjects sub  ON sub.id = a.subject_id
WHERE NOT EXISTS (
    SELECT 1
    FROM stage_subject ss
    WHERE ss.subject_id = a.subject_id
      AND ss.stage_id   = c.stage_id
);


-- ───────────────────────────────────────────────────────────────
-- ٣. حصص صاحبها غير صاحب التكليف
--    الحصة مسجّلة لمعلم، والتكليف المربوط فيها لمعلم تاني
-- ───────────────────────────────────────────────────────────────
SELECT
    w.id            AS lesson_id,
    w.day_of_week,
    w.period_number,
    w.teacher_id    AS lesson_teacher_id,
    a.teacher_id    AS assignment_teacher_id
FROM weekly_schedules w
JOIN teacher_assignments a ON a.id = w.teacher_assignment_id
WHERE w.teacher_id <> a.teacher_id;


-- ───────────────────────────────────────────────────────────────
-- ٤. حصص بلا تكليف — بتظهر بلا مادة في الجداول
-- ───────────────────────────────────────────────────────────────
SELECT
    id              AS lesson_id,
    teacher_id,
    day_of_week,
    period_number
FROM weekly_schedules
WHERE teacher_assignment_id IS NULL
  AND type = 'class';


-- ═══════════════════════════════════════════════════════════════
--  قبل الحذف — شوف شو رح ينحذف معه
-- ═══════════════════════════════════════════════════════════════
--
--  حذف التكليف بيجرّ معه (CASCADE):
--      weekly_schedules  ← حصص الجدول
--      assignments       ← واجبات الطلاب
--      teacher_tasks     ← مهام المعلم
--
--  وبينجو منه (SET NULL):
--      grades            ← العلامات بتضل، بس بتفقد ربطها بالتكليف
--      grade_submissions
--
--  بدّل الأرقام بأرقام التكليفات يلي طلعت فوق:
-- ───────────────────────────────────────────────────────────────
SELECT
    a.id                              AS assignment_id,
    COUNT(DISTINCT w.id)              AS lessons_deleted,
    COUNT(DISTINCT asg.id)            AS student_assignments_deleted,
    COUNT(DISTINCT tt.id)             AS teacher_tasks_deleted,
    COUNT(DISTINCT g.id)              AS grades_kept_but_unlinked
FROM teacher_assignments a
LEFT JOIN weekly_schedules w   ON w.teacher_assignment_id   = a.id
LEFT JOIN assignments     asg  ON asg.teacher_assignment_id = a.id
LEFT JOIN teacher_tasks   tt   ON tt.teacher_assignment_id  = a.id
LEFT JOIN grades          g    ON g.teacher_assignment_id   = a.id
WHERE a.id IN (0)          -- ← حط أرقام التكليفات هون، مثلاً (11, 14)
GROUP BY a.id;


-- ═══════════════════════════════════════════════════════════════
--  الحذف — ما تشغّله إلا بعد ما تقرأ نتيجة الاستعلام يلي فوق
-- ═══════════════════════════════════════════════════════════════
-- DELETE FROM teacher_assignments WHERE id IN (0);   -- ← حط الأرقام
