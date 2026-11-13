// Widget tests with a fake server (no network): login, home, the vessel lists and helpers.
import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ysms_app/api.dart';
import 'package:ysms_app/bio.dart';
import 'package:ysms_app/main.dart';
import 'package:ysms_app/ui.dart';

class FakeServer implements HttpClientAdapter {
  bool signedIn = false;
  final calls = <String>[];

  ResponseBody _json(Object o, [int code = 200, Map<String, List<String>>? h]) => ResponseBody.fromString(
    jsonEncode(o),
    code,
    headers: {
      Headers.contentTypeHeader: ['application/json'],
      ...?h,
    },
  );

  @override
  Future<ResponseBody> fetch(RequestOptions o, Stream<Uint8List>? body, Future<void>? cancel) async {
    final path = o.uri.path.split('/').last;
    final a = o.uri.queryParameters['a'] ?? '';
    calls.add('${o.method} $path${a.isEmpty ? '' : '?a=$a'}');
    if (path == 'login.php') {
      final data = o.data;
      if (data is Map && data['password'] == 'pw') {
        signedIn = true;
        return ResponseBody.fromString(
          '',
          302,
          headers: {
            'location': ['index.php'],
            'set-cookie': ['PHPSESSID=abc123; path=/; HttpOnly'],
          },
        );
      }
      return ResponseBody.fromString(
        '<html>Wrong</html>',
        200,
        headers: {
          Headers.contentTypeHeader: ['text/html'],
        },
      );
    }
    if (path == 'app_api.php') {
      if (!signedIn) return _json({'error': 'login'}, 401);
      switch (a) {
        case 'me':
          return _json({
            'id': 1,
            'name': 'Ravi Kumar',
            'first': 'Ravi',
            'initials': 'RK',
            'role': 'Admin',
            'avatar': '',
            'greeting': 'Good Morning',
            'unread': 3,
            'csrf': 'tok',
            'can': {'assign': true, 'inbox': true, 'chat': true, 'recovery': true, 'report_generator': true},
          });
        case 'dashboard':
          return _json({
            'counts': {'pending_vessel': 12, 'pending_report': 7, 'completed': 340, 'cancelled': 2},
            'recovery_total': 12345.5,
            'recovery_month': 98.25,
            'ships_month': 9,
            'month': 'November 2026',
            'attention': [
              {'id': 5, 'vessel': 'MV Ocean Star', 'status': 'Pending Report', 'kind': 'report', 'days': 9, 'port': 'Kakinada'},
            ],
            'today': '13 Nov 2026',
          });
        case 'insights':
          return _json({'recent': null, 'avg_ships_month': 4.5});
        case 'list':
          return _json({
            'total': 1,
            'page': 1,
            'more': false,
            'items': [
              {
                'id': 5,
                'vessel': 'MV Ocean Star',
                'client': 'Blue Lines',
                'port': 'Kakinada',
                'type': 'Bunker Survey',
                'surveyors': 'Ravi',
                'appointment': '01 Nov 2026',
                'completed_on': '',
                'report_on': '',
                'update': 'ETA 22nd',
                'overdue': null,
                'status': 'Pending Vessel',
                'img': null,
                'img_k': 'OCEAN STAR',
                'img_st': 'missing',
              },
            ],
          });
      }
      return _json({'error': 'unknown'}, 400);
    }
    if (path == 'logout.php') {
      signedIn = false;
      return ResponseBody.fromString(
        '',
        302,
        headers: {
          'set-cookie': ['PHPSESSID=deleted; expires=Thu, 01-Jan-1970 00:00:01 GMT; path=/'],
        },
      );
    }
    return _json({}, 404);
  }

  @override
  void close({bool force = false}) {}
}

Future<void> settle(WidgetTester t, [int n = 12]) async {
  for (var i = 0; i < n; i++) {
    await t.pump(const Duration(milliseconds: 120));
  }
}

void main() {
  late FakeServer server;
  late MemoryCookies jar;

  setUp(() {
    Prefs.memoryOnly = true;
    server = FakeServer();
    jar = MemoryCookies();
    Api.i = Api(cookies: jar, adapter: server);
  });

  testWidgets('signed out: the login screen, a wrong password shows the error', (t) async {
    await t.binding.setSurfaceSize(const Size(400, 900));
    await t.pumpWidget(const YsmsApp());
    await settle(t);
    expect(find.text('Welcome back'), findsOneWidget);
    await t.enterText(find.byKey(const ValueKey('username')), 'ravi');
    await t.enterText(find.byKey(const ValueKey('password')), 'nope');
    await t.tap(find.byKey(const ValueKey('signin')));
    await settle(t);
    expect(find.text('Wrong username or password.'), findsOneWidget);
    expect(server.calls, contains('POST login.php'));
  });

  testWidgets('right password: home with the counts, the session cookie kept', (t) async {
    await t.binding.setSurfaceSize(const Size(400, 900));
    await t.pumpWidget(const YsmsApp());
    await settle(t);
    await t.enterText(find.byKey(const ValueKey('username')), 'ravi');
    await t.enterText(find.byKey(const ValueKey('password')), 'pw');
    await t.tap(find.byKey(const ValueKey('signin')));
    await settle(t, 20);
    expect(find.text('Ravi Kumar'), findsOneWidget);
    expect(find.text('12'), findsOneWidget);
    expect(find.text('340'), findsOneWidget);
    expect(find.text('MV Ocean Star'), findsOneWidget); // needs attention
    expect(jar.jar['PHPSESSID'], 'abc123');
    // Vessels tab: the list
    await t.tap(find.text('Vessels'));
    await settle(t, 16);
    expect(find.text('Pending Vessels'), findsWidgets);
    expect(find.text('Blue Lines'), findsOneWidget);
    expect(find.textContaining('ETA 22nd'), findsOneWidget);
  });

  testWidgets('session ended on the server: back to the login screen', (t) async {
    server.signedIn = true;
    await t.binding.setSurfaceSize(const Size(400, 900));
    await t.pumpWidget(const YsmsApp());
    await settle(t, 20);
    expect(find.text('Ravi Kumar'), findsOneWidget);
    server.signedIn = false;
    await t.tap(find.text('Vessels'));
    await settle(t, 20);
    expect(find.text('Welcome back'), findsOneWidget);
    expect(find.textContaining('signed out'), findsOneWidget);
  });

  test('MT figures use Indian grouping with 3 decimals', () {
    expect(fmtMt(12345.5), '12,345.500');
    expect(fmtMt(1234567.25), '12,34,567.250');
    expect(fmtMt(0), '0.000');
    expect(fmtMt('98.2'), '98.200');
  });

  test('Set-Cookie: kept, and removed when expired', () async {
    final j = MemoryCookies();
    await j.save(['ysms_remember=a%3Ab; expires=Thu, 13-Nov-2036 10:00:00 GMT; Max-Age=315360000; path=/; HttpOnly']);
    expect(j.jar['ysms_remember'], 'a%3Ab');
    await j.save(['ysms_remember=deleted; expires=Thu, 01-Jan-1970 00:00:01 GMT; Max-Age=0; path=/']);
    expect(j.jar.containsKey('ysms_remember'), isFalse);
    expect(parseSetCookie('PHPSESSID=x; path=/; secure; HttpOnly')!.secure, isTrue);
  });
}
