<?php

namespace App\Services;

class PasswordStrengthService
{
    
    // تحليل قوة كلمة المرور
    
    public function validate(string $password): array
    {
        $score = 0;
        $feedback = [];
        $checks = [];

        // الطول 
        if (strlen($password) >= 8) {
            $score += 20;
            $checks['length_8'] = true;
        } else {
            $feedback[] = 'كلمة المرور قصيرة جداً (أقل من 8 أحرف)';
            $checks['length_8'] = false;
        }

        if (strlen($password) >= 12) {
            $score += 10;
            $checks['length_12'] = true;
        } else {
            $checks['length_12'] = false;
        }

        // أحرف كبيرة
        if (preg_match('/[A-Z]/', $password)) {
            $score += 15;
            $checks['uppercase'] = true;
        } else {
            $feedback[] = 'أضف حرفاً كبيراً (A-Z)';
            $checks['uppercase'] = false;
        }

        //أحرف صغيرة
        if (preg_match('/[a-z]/', $password)) {
            $score += 15;
            $checks['lowercase'] = true;
        } else {
            $feedback[] = 'أضف حرفاً صغيراً (a-z)';
            $checks['lowercase'] = false;
        }

        // أرقام
        if (preg_match('/[0-9]/', $password)) {
            $score += 20;
            $checks['numbers'] = true;
        } else {
            $feedback[] = 'أضف رقماً (0-9)';
            $checks['numbers'] = false;
        }

        // رموز خاصة
        if (preg_match('/[^a-zA-Z0-9]/', $password)) {
            $score += 20;
            $checks['symbols'] = true;
        } else {
            $feedback[] = 'أضف رمزاً خاصاً (@#$%...)';
            $checks['symbols'] = false;
        }

        // تجنب الأنماط الشائعة
        $commonPatterns = ['123', 'abc', 'password', 'qwerty', 'admin'];
        $hasCommonPattern = false;
        foreach ($commonPatterns as $pattern) {
            if (stripos($password, $pattern) !== false) {
                $hasCommonPattern = true;
                break;
            }
        }
        if (!$hasCommonPattern) {
            $score += 5;
            $checks['no_patterns'] = true;
        } else {
            $feedback[] = 'تجنب الأنماط الشائعة (123, abc...)';
            $checks['no_patterns'] = false;
        }

        // تحديد القوة
        $strength = match(true) {
            $score >= 80 => 'قوية جداً ',
            $score >= 60 => 'قوية ',
            $score >= 40 => 'متوسطة ',
            default => 'ضعيفة '
        };

        return [
            'score' => $score,
            'max_score' => 100,
            'strength' => $strength,
            'feedback' => $feedback,
            'is_valid' => $score >= 60,
            'checks' => $checks,
            'color' => match(true) {
                $score >= 80 => '#4CAF50', // أخضر
                $score >= 60 => '#FFC107', // أصفر
                $score >= 40 => '#FF9800', // برتقالي
                default => '#F44336'       // أحمر
            }
        ];
    }

    /**
     * فحص سريع (true/false)
     */
    public function isStrong(string $password): bool
    {
        $result = $this->validate($password);
        return $result['is_valid'];
    }
}