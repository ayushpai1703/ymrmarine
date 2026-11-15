// Widget tests of the screens moved from the web app to Flutter (fake server, no network).
import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ysms_app/api.dart';
import 'package:ysms_app/bio.dart';
import 'package:ysms_app/main.dart' show nativeScreen;
import 'package:ysms_app/screens/formats.dart';
import 'package:ysms_app/screens/history.dart';
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

  test('routes with a Flutter screen', () {
    expect(nativeScreen('/h/12'), isA<HistoryScreen>());
    expect(nativeScreen('/formats'), isA<FormatsScreen>());
    expect(nativeScreen('/chat'), isNull);
  });
}
