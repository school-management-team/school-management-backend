<?php

namespace App\Services;
use App\Models\Student;
use App\Models\Guardian;

class GuardianVerificationService
{
    /*
      التحقق وربط ولي الأمر بالطالب
      يستخدم عند:
       تفعيل الحساب لأول مرة
      أو إضافة طالب جديد من داخل الحساب
     */
    public function verifyMatch(Guardian $guardian, string $studentNumber): array
    {
        //  البحث عن الطالب
        $student = Student::where('student_number', $studentNumber)->first();

        if (!$student) {
            return [
                'success' => false,
                'message' => 'الرقم المدرسي غير موجود'
            ];
        }

        // التحقق من العلاقة
        if ($guardian->relationship === 'father') {

            if (!$this->matchName($student->father_name, $guardian->user->user_name)) {

                return [
                    'success' => false,
                    'message' => 'لا يوجد تطابق مع اسم الأب المسجل للطالب'
                ];
            }
        }

        if ($guardian->relationship === 'mother') {

            if (!$this->matchName($student->mother_name, $guardian->user->user_name)) {

                return [
                    'success' => false,
                    'message' => 'لا يوجد تطابق مع اسم الأم المسجلة للطالب'
                ];
            }
        }


        return [
            'success' => true,
            'student' => $student
        ];
    }

   // إضافة طالب جديد لاحقاً من داخل حساب ولي الأمر

    public function addStudentToGuardian(Guardian $guardian, string $studentNumber)
    {
        $result= $this->verifyMatch($guardian, $studentNumber);

        if(!$result['success']){
            return $result;
        }

        $student=Student::where('student_number',$studentNumber)->first();

        if (!$guardian->students()->where('student_id', $student->id)->exists()) {
            $guardian->students()->attach($student->id);
        }

        return [
        'success' => true,
        'message' => 'تم ربط الطالب بنجاح',
        'student' => $student,
        ];
    }

    // مقارنة بسيطة للأسماء (تطبيع خفيف)

    private function matchName(string $name1, string $name2): bool
    {
        $n1 = $this->normalize($name1);
        $n2 = $this->normalize($name2);

        return $n1 === $n2;
    }


    private function normalize(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s+/', ' ', $value);

        $map = [
            'أ' => 'ا',
            'إ' => 'ا',
            'آ' => 'ا',
            'ى' => 'ي',
            'ة' => 'ه',
        ];

        return strtr($value, $map);
    }
}
