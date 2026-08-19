# Real-Time Notifications — Front-End Handover

Backend: Laravel 11 + Reverb + Laravel Notifications (`database` + `broadcast`).
Clients: React dashboard, Flutter mobile app.

---

## 1. Environment variables

### Backend (`.env`) — already configured

```env
BROADCAST_CONNECTION=reverb
QUEUE_CONNECTION=database          # notifications are queued

REVERB_APP_ID=school-management
REVERB_APP_KEY=<32-hex>            # public — safe to ship to clients
REVERB_APP_SECRET=<32-hex>         # server only — never expose
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http

REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080
```

Ask the backend team for the real `REVERB_APP_KEY`. Only the **key** is needed
by clients; the secret never leaves the server.

### React (`.env`)

```env
VITE_API_URL=http://127.0.0.1:8000
VITE_REVERB_APP_KEY=<same key>
VITE_REVERB_HOST=127.0.0.1
VITE_REVERB_PORT=8080
VITE_REVERB_SCHEME=http
```

### Flutter (`--dart-define` or a config class)

```dart
const apiUrl       = String.fromEnvironment('API_URL',       defaultValue: 'http://10.0.2.2:8000');
const reverbKey    = String.fromEnvironment('REVERB_KEY');
const reverbHost   = String.fromEnvironment('REVERB_HOST',   defaultValue: '10.0.2.2');
const reverbPort   = int.fromEnvironment('REVERB_PORT',      defaultValue: 8080);
const reverbScheme = String.fromEnvironment('REVERB_SCHEME', defaultValue: 'http');
```

> Android emulator reaches the host machine at `10.0.2.2`, not `127.0.0.1`.
> iOS simulator uses `127.0.0.1`. Physical devices need the LAN IP.

### Running the server

```bash
php artisan reverb:start        # WebSocket server, port 8080
php artisan queue:work          # REQUIRED — notifications are queued
php artisan serve               # REST API, port 8000
```

Without `queue:work`, notifications sit in the jobs table and never arrive.

---

## 2. WebSocket authentication

All channels are **private**. Every subscription is authenticated.

```
POST  {API_URL}/api/broadcasting/auth
Headers:
    Authorization: Bearer <sanctum-token>
    Accept: application/json
```

This is under the `api` prefix with `auth:sanctum` — **not** the default
session-based `/broadcasting/auth` — because both clients use token auth.
Point the Echo / Pusher SDK at this exact path.

Failure modes:
- `401` — missing or invalid token
- `403` — token valid, but the user may not join that channel

---

## 3. Channels and events

### Channel patterns

| Channel | Who may subscribe | Use |
|---|---|---|
| `private-user.{userId}` | that user only, and only if `status = active` | **primary — all personal notifications** |
| `private-section.{sectionId}` | students of the section, teachers assigned to it, guardians of its students, supervisors, admins | class-wide broadcasts |
| `private-role.{role}` | users holding that role | role-wide announcements |

> Echo adds the `private-` prefix itself: `Echo.private('user.5')`.
> Raw WebSocket clients must send the full name `private-user.5`.

**In practice the clients only need `private-user.{id}`.** Every notification is
delivered per user, so a single subscription covers all types.

### Event name

One event for every notification type:

```
.notification.created
```

The leading dot tells Echo "raw event name, do not namespace it".

Type discrimination happens through the `type` field inside the payload, so the
client subscribes once and switches on `type`. **Adding a backend notification
type requires no client change.**

### Registered types

| `type` | Sent by | Reaches | Priority |
|---|---|---|---|
| `assignment.published` | teacher (automatic on publish) | students of the section | high |
| `class.announcement` | teacher | students of the section | normal |
| `student.academic_drop` | supervisor | the student's teachers + guardians | high |
| `meeting.scheduled` | supervisor | guardians (+ teachers, optional) | high |
| `school.announcement` | supervisor | reserved | normal |
| `substitution.assigned` | supervisor | reserved | high |

---

## 4. Broadcast payload

Every event carries the same envelope:

```json
{
  "type": "assignment.published",
  "title": "واجب جديد",
  "message": "واجب جديد في رياضيات: تمارين الوحدة 3 — التسليم 2026-09-01",
  "icon": "assignment",
  "priority": "high",
  "data": {
    "assignment_id": 12,
    "title": "تمارين الوحدة 3",
    "due_date": "2026-09-01",
    "max_grade": 100,
    "subject": "رياضيات",
    "section_id": 2,
    "teacher_name": "أستاذ الرياضيات"
  },
  "created_at": "2026-08-19T10:15:00+00:00"
}
```

Fixed fields: `type`, `title`, `message`, `icon`, `priority`, `data`, `created_at`.
Only `data` varies per type — render the envelope generically and treat `data`
as type-specific detail.

`priority: "high"` is the signal to play a sound / vibrate / show a heads-up
notification. `normal` should be silent.

### `data` per type

| Type | Keys inside `data` |
|---|---|
| `assignment.published` | `assignment_id`, `title`, `due_date`, `max_grade`, `subject`, `section_id`, `teacher_name` |
| `class.announcement` | `section_id`, `section_name`, `class_name`, `teacher_id`, `teacher_name`, `subject` |
| `student.academic_drop` | `student_id`, `student_name`, `student_number`, `section_id`, `subject_id`, `subject`, `previous_value`, `current_value`, `drop`, `note` |
| `meeting.scheduled` | `student_id`, `student_name`, `meeting_date`, `meeting_time`, `location`, `reason` |

> Laravel adds its own `id` field (the notification UUID) alongside the payload.
> Use it to de-duplicate against records fetched from the REST API.

---

## 5. REST API

All endpoints need `Authorization: Bearer <token>` and act only on the
authenticated user's own notifications.

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/api/notifications` | paginated list |
| `GET` | `/api/notifications/unread-count` | badge counter |
| `PATCH` | `/api/notifications/{id}/read` | mark one as read |
| `PATCH` | `/api/notifications/read-all` | mark all as read |
| `DELETE` | `/api/notifications/{id}` | remove from history |

### `GET /api/notifications`

Query: `only_unread=1` · `type=assignment.published` · `per_page=20`

```json
{
  "success": true,
  "message": "عدد الإشعارات: 34",
  "data": {
    "notifications": [
      {
        "id": "9b1f...uuid",
        "type": "assignment.published",
        "title": "واجب جديد",
        "message": "...",
        "icon": "assignment",
        "priority": "high",
        "data": { "assignment_id": 12 },
        "is_read": false,
        "read_at": null,
        "created_at": "2026-08-19T10:15:00+00:00"
      }
    ],
    "unread_count": 7,
    "total": 34,
    "current_page": 1,
    "last_page": 2,
    "per_page": 20
  }
}
```

A notification object has the **same shape** whether it arrived over the socket
or from this endpoint (plus `id`, `is_read`, `read_at`) — one renderer handles both.

### Mark-as-read responses

Both mark endpoints return `changed: false` when nothing needed changing
(already read / nothing unread) rather than falsely reporting success.

```json
{ "success": true, "changed": true, "message": "تم تعليم الإشعار كمقروء",
  "data": { "notification": { }, "unread_count": 6 } }
```

### Sending endpoints (dashboard UI)

| Method | Path | Role | Body |
|---|---|---|---|
| `POST` | `/api/teacher/notifications/class-announcement` | teacher | `section_id`, `title`, `body` |
| `POST` | `/api/supervisor/notifications/academic-drop` | supervisor | `student_id`, `subject_id`, `previous_value`, `current_value`, `note?`, `notify?[teachers,guardians]` |
| `POST` | `/api/supervisor/notifications/parent-meeting` | supervisor | `student_id`, `meeting_date` (Y-m-d), `meeting_time` (H:i), `location?`, `reason?`, `notify_teachers?` |

`assignment.published` fires automatically when a teacher publishes homework —
no client call needed.

Each returns the recipient count:

```json
{ "success": true, "message": "تم إرسال الإعلان إلى 24 طالب",
  "data": { "recipients": 24, "section_id": 2 } }
```

---

# Front-End Integration

## React — clean architecture

```
src/
  core/
    config/env.ts                  # env vars in one place
    api/httpClient.ts              # axios instance + bearer token
  features/notifications/
    domain/
      Notification.ts              # entity + type guards
      NotificationRepository.ts    # interface (no implementation)
    data/
      notificationApi.ts           # REST calls
      echoClient.ts                # WebSocket connection (singleton)
    application/
      NotificationProvider.tsx     # context: state + subscription lifecycle
      useNotifications.ts          # read-only hook for components
    presentation/
      NotificationBell.tsx
      NotificationList.tsx
```

### 1. Domain — one shape for socket and REST

```ts
// features/notifications/domain/Notification.ts
export type NotificationPriority = 'normal' | 'high';

export interface AppNotification {
  id: string;
  type: string;
  title: string;
  message: string;
  icon: string;
  priority: NotificationPriority;
  data: Record<string, unknown>;
  isRead: boolean;
  createdAt: string;
}

// Socket payload and REST row differ slightly — normalise at the boundary
// so nothing above this layer ever sees two shapes.
export const fromSocket = (raw: any): AppNotification => ({
  id: raw.id,
  type: raw.type,
  title: raw.title,
  message: raw.message,
  icon: raw.icon ?? 'bell',
  priority: raw.priority ?? 'normal',
  data: raw.data ?? {},
  isRead: false,
  createdAt: raw.created_at,
});

export const fromApi = (raw: any): AppNotification => ({
  ...fromSocket(raw),
  isRead: raw.is_read,
});
```

### 2. Data — the Echo singleton

```ts
// features/notifications/data/echoClient.ts
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { env } from '@/core/config/env';

let echo: Echo | null = null;

// One connection per session. Creating Echo inside a component would open a
// new socket on every render.
export function connectEcho(token: string): Echo {
  if (echo) return echo;

  window.Pusher = Pusher;

  echo = new Echo({
    broadcaster: 'reverb',
    key: env.reverbKey,
    wsHost: env.reverbHost,
    wsPort: env.reverbPort,
    wssPort: env.reverbPort,
    forceTLS: env.reverbScheme === 'https',
    enabledTransports: ['ws', 'wss'],

    // token auth, not cookies
    authEndpoint: `${env.apiUrl}/api/broadcasting/auth`,
    auth: { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } },
  });

  return echo;
}

export function disconnectEcho(): void {
  echo?.disconnect();
  echo = null;
}
```

### 3. Application — provider owns the subscription

```tsx
// features/notifications/application/NotificationProvider.tsx
import { createContext, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { connectEcho, disconnectEcho } from '../data/echoClient';
import { fetchNotifications, markRead, markAllRead } from '../data/notificationApi';
import { fromApi, fromSocket, AppNotification } from '../domain/Notification';

export const NotificationContext = createContext<Ctx | null>(null);

export function NotificationProvider({ token, userId, children }) {
  const [items, setItems] = useState<AppNotification[]>([]);
  const [unread, setUnread] = useState(0);

  // Guards against React 18 StrictMode double-mount opening two subscriptions
  const subscribed = useRef(false);

  useEffect(() => {
    if (!token || !userId) return;

    fetchNotifications().then(({ notifications, unread_count }) => {
      setItems(notifications.map(fromApi));
      setUnread(unread_count);
    });

    if (subscribed.current) return;
    subscribed.current = true;

    const echo = connectEcho(token);

    echo.private(`user.${userId}`).listen('.notification.created', (raw: any) => {
      const incoming = fromSocket(raw);

      setItems(prev =>
        prev.some(n => n.id === incoming.id) ? prev : [incoming, ...prev]
      );
      setUnread(c => c + 1);

      if (incoming.priority === 'high') playChime();
    });

    return () => {
      echo.leave(`user.${userId}`);
      disconnectEcho();
      subscribed.current = false;
    };
  }, [token, userId]);

  const markOne = useCallback(async (id: string) => {
    setItems(prev => prev.map(n => (n.id === id ? { ...n, isRead: true } : n)));
    setUnread(c => Math.max(0, c - 1));
    await markRead(id);            // optimistic; reconcile on failure
  }, []);

  const markAll = useCallback(async () => {
    setItems(prev => prev.map(n => ({ ...n, isRead: true })));
    setUnread(0);
    await markAllRead();
  }, []);

  // Stable identity — without useMemo every consumer re-renders on any state change
  const value = useMemo(
    () => ({ items, unread, markOne, markAll }),
    [items, unread, markOne, markAll]
  );

  return <NotificationContext.Provider value={value}>{children}</NotificationContext.Provider>;
}
```

### Avoiding redundant re-renders

- **One provider at the app root.** Never create Echo inside a component body.
- **`useMemo` the context value** — a fresh object each render re-renders every consumer.
- **`useRef` guard** for StrictMode's double mount in development.
- **Split contexts if the bell is hot**: keep `unread` in its own context so the
  badge updating does not re-render the whole list.
- **Deduplicate by `id`** — the socket payload and a later REST refetch can both
  deliver the same notification.

---

## Flutter — clean architecture

Use `pusher_channels_flutter`, not `web_socket_channel`. Reverb speaks the
Pusher protocol; a raw WebSocket client would mean hand-writing the handshake,
private-channel auth signing, ping/pong and reconnection.

```yaml
dependencies:
  pusher_channels_flutter: ^2.2.1
  flutter_local_notifications: ^17.2.3
  flutter_bloc: ^8.1.6
  dio: ^5.7.0
```

```
lib/
  core/
    config/app_config.dart
    network/dio_client.dart
  features/notifications/
    domain/
      entities/app_notification.dart
      repositories/notification_repository.dart      # abstract
    data/
      models/app_notification_model.dart             # fromSocket / fromApi
      datasources/notification_remote_ds.dart        # REST
      datasources/notification_socket_ds.dart        # Pusher stream
      repositories/notification_repository_impl.dart
    presentation/
      bloc/notification_bloc.dart
      widgets/notification_badge.dart
      pages/notifications_page.dart
  services/
    local_notification_service.dart
```

### 1. Socket data source — exposes a Stream, knows nothing about UI

```dart
class NotificationSocketDataSource {
  final PusherChannelsFlutter _pusher = PusherChannelsFlutter.getInstance();
  final _controller = StreamController<AppNotificationModel>.broadcast();

  Stream<AppNotificationModel> get stream => _controller.stream;

  Future<void> connect({required String token, required int userId}) async {
    await _pusher.init(
      apiKey: AppConfig.reverbKey,
      cluster: '',                       // Reverb ignores clusters
      useTLS: AppConfig.reverbScheme == 'https',
      host: AppConfig.reverbHost,
      wsPort: AppConfig.reverbPort,
      wssPort: AppConfig.reverbPort,

      // token auth for private channels
      onAuthorizer: (channelName, socketId, options) async {
        final res = await dio.post(
          '${AppConfig.apiUrl}/api/broadcasting/auth',
          data: {'socket_id': socketId, 'channel_name': channelName},
          options: Options(headers: {'Authorization': 'Bearer $token'}),
        );
        return res.data;                 // {"auth": "key:signature"}
      },

      onEvent: (event) {
        if (event.eventName != 'notification.created') return;
        final raw = jsonDecode(event.data);
        _controller.add(AppNotificationModel.fromSocket(raw));
      },
    );

    await _pusher.subscribe(channelName: 'private-user.$userId');
    await _pusher.connect();
  }

  Future<void> disconnect() async {
    await _pusher.disconnect();
    await _controller.close();
  }
}
```

> The event name arrives **without** the leading dot. Compare against
> `'notification.created'`.

### 2. Bloc — the only place socket and REST meet

```dart
class NotificationBloc extends Bloc<NotificationEvent, NotificationState> {
  final NotificationRepository _repo;
  StreamSubscription? _sub;

  NotificationBloc(this._repo) : super(NotificationInitial()) {
    on<NotificationsRequested>(_onRequested);
    on<NotificationReceived>(_onReceived);
    on<NotificationMarkedRead>(_onMarkedRead);
  }

  Future<void> _onRequested(e, emit) async {
    emit(NotificationLoading());
    final page = await _repo.fetch();
    emit(NotificationLoaded(items: page.items, unread: page.unread));

    // start listening only after history is loaded, so ordering is stable
    _sub ??= _repo.stream().listen((n) => add(NotificationReceived(n)));
  }

  void _onReceived(NotificationReceived e, emit) {
    final s = state;
    if (s is! NotificationLoaded) return;

    // socket and a later refetch can both deliver the same record
    if (s.items.any((n) => n.id == e.notification.id)) return;

    emit(s.copyWith(items: [e.notification, ...s.items], unread: s.unread + 1));

    LocalNotificationService.show(e.notification);
  }

  @override
  Future<void> close() {
    _sub?.cancel();
    return super.close();
  }
}
```

### 3. Local notifications — background and foreground

```dart
class LocalNotificationService {
  static final _plugin = FlutterLocalNotificationsPlugin();

  static Future<void> init() async {
    await _plugin.initialize(
      const InitializationSettings(
        android: AndroidInitializationSettings('@mipmap/ic_launcher'),
        iOS: DarwinInitializationSettings(),
      ),
      onDidReceiveNotificationResponse: (r) {
        // r.payload carries the notification id — deep-link to its screen
      },
    );
  }

  static Future<void> show(AppNotification n) async {
    // priority from the backend decides the channel: high = sound + heads-up
    final isHigh = n.priority == 'high';

    await _plugin.show(
      n.id.hashCode,
      n.title,
      n.message,
      NotificationDetails(
        android: AndroidNotificationDetails(
          isHigh ? 'school_high' : 'school_normal',
          isHigh ? 'تنبيهات مهمة' : 'إشعارات عامة',
          importance: isHigh ? Importance.high : Importance.low,
          priority: isHigh ? Priority.high : Priority.low,
          playSound: isHigh,
        ),
        iOS: DarwinNotificationDetails(presentSound: isHigh),
      ),
      payload: n.id,
    );
  }
}
```

### Background behaviour — read this

A WebSocket **does not survive** the app being backgrounded on Android or iOS.
The OS suspends the socket within seconds to save battery.

- **App in foreground** → Reverb delivers, `LocalNotificationService.show` fires.
- **App backgrounded/killed** → nothing arrives over the socket.

Two options:

1. **Catch-up on resume (implemented today).** Hook `AppLifecycleState.resumed`
   and dispatch `NotificationsRequested`. Nothing is lost — every notification
   is persisted in the `notifications` table and returned by the REST endpoint.
   Adequate for a school app where alerts are not second-critical.

2. **Firebase Cloud Messaging (future work).** For true background push, FCM is
   required — no WebSocket can do it. The backend is ready for this: add `fcm`
   to the `channels` array of a type in `config/notifications.php` and implement
   `toFcm()` in the notification class. No other change is needed.

```dart
class _AppState extends State<App> with WidgetsBindingObserver {
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      context.read<NotificationBloc>().add(NotificationsRequested());
    }
  }
}
```

---

## Adding a new notification type

No client change required.

1. Add an entry to `config/notifications.php` under `types`.
2. Create a class extending `BaseNotification` with `type()`, `message()`, `payload()`.
3. Send it: `$dispatcher->send($dispatcher->to()->studentsOfSection($id), new YourNotification(...));`

The envelope, channels, storage, REST endpoints and the client listener all
keep working — the new type simply appears with its own `type` string.

`RecipientResolver` already answers: students of a section, guardians of a
section or of one student, teachers of a section or of one student, everyone
with a given role.
