import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ysms_login/main.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  final calls = <MethodCall>[];
  Map<String, Object?> state = {};

  setUp(() {
    calls.clear();
    state = {'error': '', 'username': '', 'fp': false, 'fpName': '', 'version': 'v9'};
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger.setMockMethodCallHandler(channel, (c) async {
      calls.add(c);
      return c.method == 'state' ? state : null;
    });
  });

  Future<void> page(WidgetTester t) async {
    t.view.physicalSize = const Size(1080, 2340);
    t.view.devicePixelRatio = 2.75;
    addTearDown(t.view.reset);
    await t.pumpWidget(const LoginApp());
    await t.pumpAndSettle();
  }

  Future<void> fromNative(String method, Object? args) async {
    await TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger.handlePlatformMessage(
        'ysms/login', const StandardMethodCodec().encodeMethodCall(MethodCall(method, args)), (_) {});
  }

  testWidgets('shows the form and asks native for its state', (t) async {
    await page(t);
    expect(find.text('Welcome back'), findsOneWidget);
    expect(find.text('Username'), findsOneWidget);
    expect(find.text('Password'), findsOneWidget);
    expect(find.text('Keep me signed in'), findsOneWidget);
    expect(find.byKey(const ValueKey('fingerprint')), findsNothing);
    expect(calls.first.method, 'state');
    expect(find.textContaining('v9'), findsOneWidget);
  });

  testWidgets('empty fields: error, nothing sent', (t) async {
    await page(t);
    await t.tap(find.byKey(const ValueKey('signin')));
    await t.pumpAndSettle();
    expect(find.text('Enter your username and password.'), findsOneWidget);
    expect(calls.where((c) => c.method == 'submit'), isEmpty);
  });

  testWidgets('sign in sends the fields; an error from the page shows and re-enables', (t) async {
    await page(t);
    await t.enterText(find.byKey(const ValueKey('username')), '  admin ');
    await t.enterText(find.byKey(const ValueKey('password')), 'pw');
    await t.tap(find.byKey(const ValueKey('remember')));
    await t.pump();
    await t.tap(find.byKey(const ValueKey('signin')));
    await t.pump();
    final s = calls.lastWhere((c) => c.method == 'submit');
    expect(s.arguments, {'username': 'admin', 'password': 'pw', 'remember': false});
    expect(find.text('Signing in…'), findsOneWidget);
    await fromNative('error', 'Wrong username or password.');
    await t.pumpAndSettle();
    expect(find.text('Wrong username or password.'), findsOneWidget);
    expect(find.text('Sign in'), findsOneWidget);
  });

  testWidgets('fingerprint button when the phone has it; forgot password', (t) async {
    state = {'error': '', 'username': 'surveyor', 'fp': true, 'fpName': 'Ravi', 'version': ''};
    await page(t);
    expect(find.text('Fingerprint · Ravi'), findsOneWidget);
    expect(find.text('surveyor'), findsOneWidget);
    await t.ensureVisible(find.byKey(const ValueKey('fingerprint')));
    await t.pumpAndSettle();
    await t.tap(find.byKey(const ValueKey('fingerprint')));
    await t.pump();
    expect(calls.last.method, 'fingerprint');
    await t.ensureVisible(find.text('Forgot password?'));
    await t.pumpAndSettle();
    await t.tap(find.text('Forgot password?'));
    await t.pump();
    expect(calls.last.method, 'forgot');
  });

  testWidgets('"show" with an error from the page (e.g. fingerprint failed)', (t) async {
    await page(t);
    await fromNative('show', {'error': 'Fingerprint login did not work.', 'username': '', 'fp': false});
    await t.pumpAndSettle();
    expect(find.text('Fingerprint login did not work.'), findsOneWidget);
  });
}
