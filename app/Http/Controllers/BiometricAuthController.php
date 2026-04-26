<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BiometricAuthController extends Controller
{
    
    // تسجيل بصمة جديدة (للحساب الحالي)
     
    public function registerOptions(Request $request)
    {
        $user = $request->user();
        
        try {
            $options = $user->createAttestationOptions();
            session(['webauthn_register' => $options]);
            
            return response()->json([
                'success' => true,
                'message' => 'تم إنشاء خيارات التسجيل',
                'data' => $options->toArray()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'فشل إنشاء خيارات التسجيل'
            ], 500);
        }
    }

    // تأكيد تسجيل البصمة
    
    public function registerConfirm(Request $request)
    {
        $user = $request->user();
        $options = session('webauthn_register');
        
        if (!$options) {
            return response()->json([
                'success' => false,
                'message' => 'انتهت صلاحية الجلسة'
            ], 400);
        }
        
        try {
            $credential = $user->createCredential(
                $options,
                $request->input('credential')
            );
            
            $user->addCredential($credential);
            session()->forget('webauthn_register');
            
            return response()->json([
                'success' => true,
                'message' => '🎉 تم تسجيل بصمتك بنجاح!'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'فشل تأكيد البصمة'
            ], 400);
        }
    }

    
    // الدخول بالبصمة
    public function loginOptions(Request $request)
    {
        $request->validate(['email' => 'required|email']);
        
        $user = User::where('email', $request->email)->first();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'المستخدم غير موجود'
            ], 404);
        }
        
        try {
            $options = $user->createAssertionOptions();
            session([
                'webauthn_login' => $options,
                'webauthn_user_id' => $user->id
            ]);
            
            return response()->json([
                'success' => true,
                'data' => $options->toArray()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'فشل إنشاء خيارات الدخول'
            ], 500);
        }
    }

    
    // تأكيد الدخول بالبصمة
    public function loginConfirm(Request $request)
    {
        $options = session('webauthn_login');
        $userId = session('webauthn_user_id');
        
        if (!$options || !$userId) {
            return response()->json([
                'success' => false,
                'message' => 'انتهت صلاحية الجلسة'
            ], 400);
        }
        
        try {
            $user = User::findOrFail($userId);
            
            $credential = $user->validateAssertion(
                $options,
                $request->input('credential')
            );
            
            if (!$credential) {
                return response()->json([
                    'success' => false,
                    'message' => 'فشل التحقق من البصمة'
                ], 401);
            }
            
            Auth::login($user);
            $token = $user->createToken('biometric_auth')->plainTextToken;
            
            session()->forget(['webauthn_login', 'webauthn_user_id']);
            
            return response()->json([
                'success' => true,
                'message' => '👆 تم الدخول بالبصمة بنجاح!',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'email' => $user->email,
                        'full_name' => $user->full_name,
                        'role' => $user->role
                    ],
                    'token' => $token
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'فشل الدخول بالبصمة'
            ], 401);
        }
    }

    
    // عرض البصمات المسجلة
    public function credentials(Request $request)
    {
        $credentials = $request->user()
            ->credentials()
            ->get()
            ->map(function ($credential) {
                return [
                    'id' => $credential->getKey(),
                    'name' => $credential->name ?? 'بصمة',
                    'created_at' => $credential->created_at->format('Y-m-d H:i'),
                    'last_used_at' => optional($credential->last_used_at)->format('Y-m-d H:i')
                ];
            });
        
        return response()->json([
            'success' => true,
            'data' => [
                'credentials' => $credentials,
                'has_biometric' => $request->user()->hasCredentials()
            ]
        ]);
    }

    
    // حذف بصمة
    public function deleteCredential(Request $request, $credentialId)
    {
        $request->user()->removeCredential($credentialId);
        
        return response()->json([
            'success' => true,
            'message' => 'تم حذف البصمة بنجاح'
        ]);
    }
}