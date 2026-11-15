// Widget tests of the screens moved from the web app to Flutter (fake server, no network).
import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ysms_app/api.dart';
import 'package:ysms_app/bio.dart';
import 'package:ysms_app/main.dart' show nativeScreen;
import 'package:ysms_app/screens/activity.dart';
import 'package:ysms_app/screens/duplicates.dart';
import 'package:ysms_app/screens/formats.dart';
import 'package:ysms_app/screens/history.dart';
import 'package:ysms_app/screens/inbox.dart';
import 'package:ysms_app/screens/me.dart';
import 'package:ysms_app/screens/recovery.dart';
import 'package:ysms_app/ui.dart';

/// app_api.php?a=… → JSON; other pages → [pages] (POSTs are recorded with their fields).
class Fake implements HttpClientAdapter {
  final api = <String, Object>{};
  final pages = <String, String>{};
  final calls = <String>[];
  final posts = <Map<String, dynamic>>[];

  ResponseBody _json(Object o, [int code = 200]) => ResponseBody.fromString(
    jsonEncode(o),
    code,
    headers: {
      Headers.contentTypeHeader: ['application/json'],
    },
  );

  @override
  Future<ResponseBody> fetch(RequestOptions o, Stream<Uint8List>? body, Future<void>? cancel) async {
    final path = o.uri.path.split('/').last;
    final a = o.uri.queryParameters['a'] ?? '';
    calls.add('${o.method} $path${a.isEmpty ? '' : '?a=$a'}');
    if (o.method == 'POST') {
      final d = o.data;
      posts.add({
        'path': path,
        if (d is Map) ...d.map((k, v) => MapEntry('$k', v)),
        if (d is FormData) ...{for (final f in d.fields) f.key: f.value},
      });
    }
    if (path == 'app_api.php') {
      final r = api[a];
      if (r == null) return _json({'error': 'not_found'}, 404);
      return _json(r);
    }
    final p = pages[path];
    if (p != null) {
      return ResponseBody.fromString(
        p,
        200,
        headers: {
          Headers.contentTypeHeader: [p.startsWith('{') ? 'application/json' : 'text/html'],
        },
      );
    }
    return ResponseBody.fromString('', 404);
  }

  @override
  void close({bool force = false}) {}
}

Future<void> settle(WidgetTester t) async {
  for (var i = 0; i < 20; i++) {
    await t.pump(const Duration(milliseconds: 100));
  }
}

Widget app(Widget w) => MaterialApp(theme: appTheme(Brightness.light), home: w);

void main() {
  late Fake server;
  setUp(() {
    Prefs.memoryOnly = true;
    server = Fake();
    Api.i = Api(cookies: MemoryCookies(), adapter: server);
    Api.i.me = {
      'id': 1,
      'name': 'Ravi Kumar',
      'initials': 'RK',
      'role': 'Admin',
      'csrf': 'tok',
      'can': <String, dynamic>{},
    };
  });

  testWidgets('Formats: the list, newest first marked New', (t) async {
    server.api['formats'] = {
      'items': [
        {
          'name': 'YMR OFFHIRE FORMAT',
          'path': 'formats/1_YMR-OFFHIRE-FORMAT.xlsx',
          'updated': '14 Nov 2026, 10:00 AM',
          'recent': true,
        },
        {
          'name': 'YMR ROB FORMAT',
          'path': 'formats/2_YMR-ROB-FORMAT.xlsx',
          'updated': '01 Oct 2026, 09:00 AM',
          'recent': false,
        },
      ],
    };
    await t.pumpWidget(app(const FormatsScreen()));
    await settle(t);
    expect(find.text('YMR OFFHIRE FORMAT'), findsOneWidget);
    expect(find.text('YMR ROB FORMAT'), findsOneWidget);
    expect(find.text('New'), findsOneWidget);
    expect(find.text('2 approved formats · tap one to open it'), findsOneWidget);
    expect(server.calls, contains('GET app_api.php?a=formats'));
  });

  testWidgets('Formats: none uploaded', (t) async {
    server.api['formats'] = {'items': []};
    await t.pumpWidget(app(const FormatsScreen()));
    await settle(t);
    expect(find.text('No formats uploaded yet'), findsOneWidget);
  });

  testWidgets('Previous surveys: each earlier survey with recovery and documents', (t) async {
    await t.binding.setSurfaceSize(const Size(400, 1200));
    server.api['history'] = {
      'vessel': 'MV OCEAN STAR',
      'items': [
        {
          'id': 7,
          'vessel': 'MV OCEAN STAR',
          'status': 'Completed',
          'report_no': 'YMR/BB/123',
          'type': 'Bunker Survey + Offhire',
          'client': 'Oceanus',
          'port': 'Kakinada',
          'surveyors': 'John Doe',
          'completed_on': '10 Oct 2026',
          'report_on': '12 Oct 2026',
          'recovery': {'total': '12.500', 'vlsfo': '10.000', 'lsmgo': '2.500', 'hsfo': '—'},
          'docs': [
            {'id': 55, 'name': 'Final Report.pdf', 'type': 'Final Report', 'when': '12 Oct 2026'},
          ],
        },
      ],
    };
    await t.pumpWidget(app(const HistoryScreen(id: 3)));
    await settle(t);
    expect(find.text('MV OCEAN STAR'), findsWidgets);
    expect(find.text('Oceanus · Kakinada'), findsOneWidget);
    expect(find.text('Surveyed 10 Oct 2026'), findsOneWidget);
    expect(find.text('12.500'), findsOneWidget);
    expect(find.text('Final Report.pdf'), findsOneWidget);
    expect(find.text('Completed'), findsOneWidget);
    expect(server.calls, contains('GET app_api.php?a=history'));
  });

  testWidgets('Previous surveys: none', (t) async {
    server.api['history'] = {'vessel': 'MV X', 'items': []};
    await t.pumpWidget(app(const HistoryScreen(id: 3)));
    await settle(t);
    expect(find.text('No earlier surveys of this vessel'), findsOneWidget);
  });

  testWidgets('Possible duplicates: clusters, filter, delete asks twice', (t) async {
    await t.binding.setSurfaceSize(const Size(400, 1400));
    server.api['duplicates'] = {
      'clusters': [
        {
          'kind': 'duplicate',
          'month': 'Oct 2026',
          'extra_mt': 12.5,
          'extra_ships': 1,
          'records': [
            {
              'id': 11,
              'vessel': 'MV TWIN',
              'report_no': 'R-1',
              'status': 'Completed',
              'type': 'Bunker',
              'surveyor': 'John',
              'completed_on': '01 Oct 2026',
              'recovery': '12.500',
              'files': 2,
              'source': 'Upload',
              'list': 'completed_surveys',
            },
            {
              'id': 12,
              'vessel': 'MV TWIN',
              'report_no': 'R-2',
              'status': 'Completed',
              'type': 'Bunker',
              'surveyor': 'John',
              'completed_on': '05 Oct 2026',
              'recovery': '12.500',
              'files': 1,
              'source': 'Import',
              'list': 'completed_surveys',
            },
          ],
        },
        {
          'kind': 'check',
          'month': 'Sep 2026',
          'extra_mt': 0,
          'extra_ships': 0,
          'records': [
            {
              'id': 21,
              'vessel': 'MV CHECK ME',
              'report_no': '',
              'status': 'Pending Report',
              'type': 'Draft',
              'surveyor': '',
              'completed_on': '',
              'recovery': '—',
              'files': 0,
              'source': 'App',
              'list': 'pending_reports',
            },
          ],
        },
      ],
    };
    server.pages['admin_surveys.php'] = '{"success":true,"message":"Deleted"}';
    await t.pumpWidget(app(const DuplicatesScreen()));
    await settle(t);
    expect(find.text('MV TWIN'), findsOneWidget);
    expect(find.text('MV CHECK ME'), findsOneWidget);
    expect(find.text('12.500 MT counted 2 times'), findsOneWidget);
    await t.tap(find.byKey(const ValueKey('dup-chip-check')));
    await settle(t);
    expect(find.text('MV TWIN'), findsNothing);
    expect(find.text('MV CHECK ME'), findsOneWidget);
    await t.tap(find.byKey(const ValueKey('dup-chip-')));
    await settle(t);
    await t.tap(find.byKey(const ValueKey('dup-del-12')));
    await settle(t);
    expect(find.text('Tap to confirm'), findsOneWidget);
    expect(server.posts, isEmpty);
    await t.tap(find.byKey(const ValueKey('dup-del-12')));
    await settle(t);
    expect(server.posts.single['path'], 'admin_surveys.php');
    expect(server.posts.single['action'], 'delete');
    expect(server.posts.single['id'], '12');
    expect(server.posts.single['type'], 'completed_surveys');
  });

  testWidgets('Login activity: summary, rows, filter', (t) async {
    await t.binding.setSurfaceSize(const Size(400, 1400));
    server.pages['login_activity_summary.php'] = jsonEncode({
      'success': true,
      'summary': {'total_users': 9, 'logged_in_today': 3, 'active_sessions': 2, 'failed_today': 1},
    });
    server.pages['login_activity_list.php'] = jsonEncode({
      'success': true,
      'total': 2,
      'page': 1,
      'per_page': 30,
      'rows': [
        {
          'id': 1,
          'user_id': 4,
          'user_name': 'Ravi Kumar',
          'username': 'ravi',
          'role': 'Admin',
          'device_type': 'Mobile',
          'browser': 'Chrome',
          'login_time': '2026-11-14 09:05:00',
          'ip_address': '1.2.3.4',
          'country': 'India',
          'login_status': 'SUCCESS',
        },
        {
          'id': 2,
          'user_id': 0,
          'user_name': '',
          'username': 'ghost',
          'role': '',
          'device_type': 'Desktop',
          'browser': 'Edge',
          'login_time': '2026-11-14 10:00:00',
          'ip_address': '5.6.7.8',
          'country': 'Unknown',
          'login_status': 'FAILED',
          'failure_reason': 'Wrong password',
        },
      ],
    });
    await t.pumpWidget(app(const ActivityScreen()));
    await settle(t);
    expect(find.text('Ravi Kumar'), findsOneWidget);
    expect(find.text('ghost'), findsOneWidget);
    expect(find.text('14 Nov, 10:00 · 5.6.7.8 · Wrong password'), findsOneWidget);
    expect(find.text('2 logins'), findsOneWidget);
    expect(find.text('Logged in today'), findsOneWidget);
    await t.tap(find.byKey(const ValueKey('act-chip-Failed')));
    await settle(t);
    expect(server.calls.where((c) => c == 'GET login_activity_list.php').length, 2);
  });

  testWidgets('Recovery: totals, list, month filter, paging', (t) async {
    await t.binding.setSurfaceSize(const Size(400, 1400));
    server.api['recovery'] = {
      'count': 2,
      'vlsfo': 10.5,
      'lsmgo': 2.25,
      'hsfo': 0,
      'total': 12.75,
      'months': [
        {'value': '2026-11', 'label': 'Nov 2026'},
      ],
      'more': false,
      'items': [
        {
          'id': 5,
          'vessel': 'MV OCEAN STAR',
          'client': 'Oceanus',
          'port': 'Kakinada',
          'type': 'Bunker Survey',
          'completed': '10 Nov 2026',
          'total': 12.75,
          'na': false,
          'total_text': '12.750',
        },
        {
          'id': 6,
          'vessel': 'MV DRAFT ONLY',
          'client': 'Oceanus',
          'port': 'Vizag',
          'type': 'Draft Survey',
          'completed': '',
          'total': null,
          'na': true,
          'total_text': 'N/A',
        },
      ],
    };
    await t.pumpWidget(app(const RecoveryScreen()));
    await settle(t);
    expect(find.text('MV OCEAN STAR'), findsOneWidget);
    expect(find.text('N/A'), findsOneWidget);
    expect(find.text('Nov 2026'), findsOneWidget);
    expect(find.byKey(const ValueKey('rec-total')), findsOneWidget);
    await t.tap(find.byKey(const ValueKey('rec-month-2026-11')));
    await settle(t);
    expect(server.calls.where((c) => c == 'GET app_api.php?a=recovery').length, 2);
  });

  testWidgets('Inbox: unread marked, tap marks read, Mark all read posts the form', (t) async {
    await t.binding.setSurfaceSize(const Size(400, 1200));
    server.api['inbox'] = {
      'total': 2,
      'unread': 1,
      'more': false,
      'items': [
        {
          'id': 1,
          'type': 'assign',
          'title': 'New vessel assigned',
          'message': 'MV OCEAN STAR at Kakinada',
          'read': false,
          'ago': '5 min ago',
          'from': 'Admin',
          'link': 'about-us.php',
          'survey_id': 0,
        },
        {
          'id': 2,
          'type': 'upload',
          'title': 'Report uploaded',
          'message': 'MV TWIN',
          'read': true,
          'ago': '1 day ago',
          'from': '',
          'link': '',
          'survey_id': 0,
        },
      ],
    };
    server.pages['inbox.php'] = '<html>ok</html>';
    await t.pumpWidget(app(const InboxScreen()));
    await settle(t);
    expect(find.text('New vessel assigned'), findsOneWidget);
    expect(find.text('1 unread'), findsOneWidget);
    expect(find.byKey(const ValueKey('notif-dot-1')), findsOneWidget);
    expect(find.byKey(const ValueKey('notif-dot-2')), findsNothing);
    await t.tap(find.byKey(const ValueKey('inbox-allread')));
    await settle(t);
    expect(server.posts.last['path'], 'inbox.php');
    expect(server.posts.last['action'], 'mark_all_read');
    expect(server.posts.last['csrf_token'], 'tok');
    await t.tap(find.byKey(const ValueKey('inbox-chip-Unread')));
    await settle(t);
    expect(server.calls.where((c) => c == 'GET app_api.php?a=inbox').length, greaterThanOrEqualTo(3));
  });

  testWidgets('My profile: details saved with profile.php, its message shown; password checks', (t) async {
    await t.binding.setSurfaceSize(const Size(400, 1400));
    server.api['profile'] = {
      'username': 'ravi',
      'full_name': 'Ravi Kumar',
      'first_name': 'Ravi',
      'last_name': 'Kumar',
      'dob': '',
      'email': 'ravi@example.com',
      'phone': '99999',
      'avatar': '',
      'surveyor_form': false,
    };
    server.api['me'] = Api.i.me!;
    server.pages['profile.php'] = '<html><div class="alert alert-success mx-3"><i class="fa-solid fa-circle-check"></i> Changes saved successfully!</div></html>';
    await t.pumpWidget(app(const MyProfileScreen()));
    await settle(t);
    expect(find.text('@ravi'), findsOneWidget);
    expect(find.byKey(const ValueKey('me-full_name')), findsOneWidget);
    expect(find.byKey(const ValueKey('me-first_name')), findsNothing);
    await t.enterText(find.byKey(const ValueKey('me-phone')), '12345');
    await t.tap(find.byKey(const ValueKey('me-save')));
    await settle(t);
    final p = server.posts.last;
    expect(p['path'], 'profile.php');
    expect(p['update_profile'], '1');
    expect(p['phone'], '12345');
    expect(p['full_name'], 'Ravi Kumar');
    expect(p['csrf_token'], 'tok');
    expect(find.text('Changes saved successfully!'), findsOneWidget);
    // password: checked before sending
    final n = server.posts.length;
    await t.enterText(find.byKey(const ValueKey('me-current_password')), 'old');
    await t.enterText(find.byKey(const ValueKey('me-new_password')), 'abc');
    await t.enterText(find.byKey(const ValueKey('me-confirm_password')), 'abc');
    await t.tap(find.byKey(const ValueKey('me-pass')));
    await settle(t);
    expect(server.posts.length, n);
    expect(find.text('The new password needs at least 6 characters.'), findsOneWidget);
  });

  testWidgets('My profile: a Surveyor edits first / last name and date of birth', (t) async {
    await t.binding.setSurfaceSize(const Size(400, 1400));
    server.api['profile'] = {
      'username': 'sv',
      'full_name': 'John Doe',
      'first_name': 'John',
      'last_name': 'Doe',
      'dob': '1990-01-02',
      'email': '',
      'phone': '',
      'avatar': '',
      'surveyor_form': true,
    };
    await t.pumpWidget(app(const MyProfileScreen()));
    await settle(t);
    expect(find.byKey(const ValueKey('me-first_name')), findsOneWidget);
    expect(find.byKey(const ValueKey('me-dob')), findsOneWidget);
    expect(find.byKey(const ValueKey('me-email')), findsNothing);
  });

  test('page messages: alert, flash toast, error', () {
    expect(pageResult('<div class="alert alert-danger x"><i></i> Wrong &amp; bad</div>').ok, isFalse);
    expect(pageResult('<div class="alert alert-danger x"><i></i> Wrong &amp; bad</div>').message, 'Wrong & bad');
    final toastOk = pageResult(
      '<div id="nativeAppToast" class="ysms-toast ysms-toast-success"><i></i><span class="ysms-toast-text">Saved</span></div>',
    );
    expect(toastOk.ok, isTrue);
    expect(toastOk.message, 'Saved');
    expect(pageResult('<html>nothing</html>').ok, isFalse);
  });

  test('routes with a Flutter screen', () {
    expect(nativeScreen('/me'), isA<MyProfileScreen>());
    expect(nativeScreen('/inbox'), isA<InboxScreen>());
    expect(nativeScreen('/recovery'), isA<RecoveryScreen>());
    expect(nativeScreen('/duplicates'), isA<DuplicatesScreen>());
    expect(nativeScreen('/activity'), isA<ActivityScreen>());
    expect(nativeScreen('/h/12'), isA<HistoryScreen>());
    expect(nativeScreen('/formats'), isA<FormatsScreen>());
    expect(nativeScreen('/chat'), isNull);
  });
}
