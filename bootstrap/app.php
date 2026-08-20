<?php

use App\Http\Middleware\CheckActive;
use App\Http\Middleware\CheckForceLogout;
use App\Http\Middleware\CheckRegistrationOpen;
use App\Http\Middleware\CheckRole;
use App\Http\Middleware\CheckVerified;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;



return Application::configure(basePath: dirname(__DIR__))
     ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role'=>CheckRole::class,
            'active'=>CheckActive::class,
            'registration.open' =>CheckRegistrationOpen::class]);
        $middleware->api([
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
    ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // أسماء عربية للموديلات، حتى تطلع الرسالة مفهومة
        $labels = [
            'Section' => 'شعبة',
            'SchoolClass' => 'صف',
            'Student' => 'طالب',
            'Teacher' => 'معلم',
            'Subject' => 'مادة',
            'TeacherAssignment' => 'تكليف',
            'WeeklySchedule' => 'حصة',
            'Announcement' => 'منشور',
            'StudentFee' => 'قسط',
            'FeePayment' => 'دفعة',
            'LessonSubstitution' => 'تعويض',
            'Guardian' => 'ولي أمر',
        ];

        $missingRecord = function ($model, $ids) use ($labels) {
            $name = class_basename($model);
            $label = $labels[$name] ?? 'العنصر';
            $id = is_array($ids) ? implode(', ', $ids) : $ids;

            return response()->json([
                'success' => false,
                'message' => "لا يوجد {$label} بالرقم {$id}",
                'data' => ['model' => $name, 'id' => $id],
            ], 404);
        };

        // findOrFail المباشر
        $exceptions->render(function (ModelNotFoundException $e, $request) use ($missingRecord) {
            if ($request->is('api/*')) {
                return $missingRecord($e->getModel(), $e->getIds());
            }
        });

        /*
         | 404 إلها سببين مختلفين تماماً، وكانوا برسالة وحدة:
         |   - المسار نفسه مش موجود (خطأ بالرابط)
         |   - المسار صح بس السجل مش موجود (خطأ بالرقم)
         | التمييز بينهن بيوفّر وقت تشخيص كتير.
         */
        $exceptions->render(function (NotFoundHttpException $e, $request) use ($missingRecord) {
            if (!$request->is('api/*')) {
                return null;
            }

            $previous = $e->getPrevious();

            if ($previous instanceof ModelNotFoundException) {
                return $missingRecord($previous->getModel(), $previous->getIds());
            }

            return response()->json([
                'success' => false,
                'message' => 'هذا المسار غير موجود — تأكد من الرابط',
                'data' => [
                    'method' => $request->method(),
                    'path' => '/'.$request->path(),
                ],
            ], 404);
        });

        // الميثود غلط: المسار موجود بس بطريقة تانية
        $exceptions->render(function (MethodNotAllowedHttpException $e, $request) {
            if (!$request->is('api/*')) {
                return null;
            }

            $allowed = $e->getHeaders()['Allow'] ?? '';

            return response()->json([
                'success' => false,
                'message' => "الميثود {$request->method()} غير مدعوم لهذا المسار. المدعوم: {$allowed}",
                'data' => [
                    'sent_method' => $request->method(),
                    'allowed_methods' => $allowed,
                    'path' => '/'.$request->path(),
                ],
            ], 405);
        });
    })->create();
