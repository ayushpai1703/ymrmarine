// YMR YSMS app — the login screen, in Flutter. Every other screen of the app stays the web app (app.php)
// inside the Capacitor shell; this screen is shown on top of it (mobile/android … FlutterLogin.java).
//
// The page does the signing in itself (so the session stays in the WebView):
//   this screen  → "submit" {username, password, remember} / "fingerprint" / "forgot" / "exit"
//   native/page  → "show" {error, username, fp, fpName, version}  ·  "error" "<message>"
import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

const navy = Color(0xFF0B1E46);
const navy2 = Color(0xFF163F86);
const blue = Color(0xFF1D5FE0);
const sky = Color(0xFF00A3DF);
const blueSoft = Color(0xFFDBE6FF);
const pageBg = Color(0xFFF5F7FC);

const channel = MethodChannel('ysms/login');

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(const LoginApp());
}

class LoginApp extends StatelessWidget {
  const LoginApp({super.key});

  @override
  Widget build(BuildContext context) {
    final scheme = ColorScheme.fromSeed(seedColor: blue, primary: blue, surface: pageBg);
    return MaterialApp(
      title: 'YMR YSMS',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(
        useMaterial3: true,
        colorScheme: scheme,
        scaffoldBackgroundColor: pageBg,
        inputDecorationTheme: InputDecorationTheme(
          filled: true,
          fillColor: Colors.white,
          contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 18),
          border: OutlineInputBorder(borderRadius: BorderRadius.circular(14)),
          enabledBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(14),
            borderSide: const BorderSide(color: Color(0xFFD0D5DD), width: 1.5),
          ),
          focusedBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(14),
            borderSide: const BorderSide(color: blue, width: 2),
          ),
        ),
      ),
      home: const LoginScreen(),
    );
  }
}

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _user = TextEditingController();
  final _pass = TextEditingController();
  final _passFocus = FocusNode();
  bool _remember = true;
  bool _obscure = true;
  bool _busy = false;
  String _error = '';
  bool _fp = false;
  String _fpName = '';
  String _version = '';

  @override
  void initState() {
    super.initState();
    channel.setMethodCallHandler((call) async {
      if (call.method == 'show') _apply(call.arguments);
      if (call.method == 'error') _fail('${call.arguments ?? ''}');
      return null;
    });
    channel.invokeMethod<Object?>('state').then(_apply).catchError((_) {});
  }

  /// What the page asked this screen to show.
  void _apply(Object? args) {
    if (!mounted || args is! Map) return;
    setState(() {
      _busy = false;
      _error = '${args['error'] ?? ''}';
      final u = '${args['username'] ?? ''}';
      if (u.isNotEmpty && _user.text.isEmpty) _user.text = u;
      _fp = args['fp'] == true;
      _fpName = '${args['fpName'] ?? ''}';
      _version = '${args['version'] ?? ''}';
      if (_error.isNotEmpty) _pass.clear();
    });
    if (_error.isNotEmpty) HapticFeedback.heavyImpact();
  }

  void _fail(String msg) {
    if (!mounted) return;
    setState(() {
      _busy = false;
      _error = msg.isEmpty ? 'Could not sign in. Try again.' : msg;
    });
    HapticFeedback.heavyImpact();
  }

  Future<void> _submit() async {
    if (_busy) return;
    final u = _user.text.trim(), p = _pass.text;
    if (u.isEmpty || p.isEmpty) {
      _fail('Enter your username and password.');
      return;
    }
    FocusScope.of(context).unfocus();
    setState(() {
      _busy = true;
      _error = '';
    });
    HapticFeedback.lightImpact();
    try {
      await channel.invokeMethod('submit', {'username': u, 'password': p, 'remember': _remember});
    } catch (_) {
      _fail('Could not connect. Check the internet and try again.');
    }
  }

  @override
  void dispose() {
    _user.dispose();
    _pass.dispose();
    _passFocus.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final mq = MediaQuery.of(context);
    const headerH = 330.0;
    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: SystemUiOverlayStyle.light.copyWith(statusBarColor: Colors.transparent, systemNavigationBarColor: pageBg),
      child: PopScope(
        canPop: false,
        onPopInvokedWithResult: (didPop, _) {
          if (!didPop) channel.invokeMethod('exit');
        },
        child: Scaffold(
          backgroundColor: navy,
          body: SingleChildScrollView(
            child: ConstrainedBox(
              constraints: BoxConstraints(minHeight: mq.size.height),
              child: Stack(
                children: [
                  const SizedBox(height: headerH + 40, width: double.infinity, child: _Header()),
                  Container(
                    margin: const EdgeInsets.only(top: headerH),
                    constraints: BoxConstraints(minHeight: mq.size.height - headerH),
                    decoration: const BoxDecoration(
                      color: pageBg,
                      borderRadius: BorderRadius.vertical(top: Radius.circular(30)),
                      boxShadow: [BoxShadow(color: Color(0x2E0B1E46), blurRadius: 30, offset: Offset(0, -10))],
                    ),
                    padding: EdgeInsets.fromLTRB(24, 14, 24, 24 + mq.padding.bottom),
                    child: _form(),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _form() {
    return AutofillGroup(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Center(
            child: Container(
              width: 40,
              height: 4,
              margin: const EdgeInsets.only(bottom: 22),
              decoration: BoxDecoration(color: const Color(0xFFD0D5DD), borderRadius: BorderRadius.circular(2)),
            ),
          ),
          AnimatedSize(
            duration: const Duration(milliseconds: 220),
            curve: Curves.easeOut,
            child: _error.isEmpty
                ? const SizedBox(width: double.infinity)
                : Container(
                    key: const ValueKey('error'),
                    margin: const EdgeInsets.only(bottom: 16),
                    padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                    decoration: BoxDecoration(
                      color: const Color(0xFFFEF3F2),
                      borderRadius: BorderRadius.circular(14),
                      border: Border.all(color: const Color(0xFFFECDCA)),
                    ),
                    child: Row(children: [
                      const Icon(Icons.error_outline_rounded, color: Color(0xFFD92D20), size: 22),
                      const SizedBox(width: 10),
                      Expanded(
                        child: Text(_error,
                            style: const TextStyle(color: Color(0xFFB42318), fontWeight: FontWeight.w600, fontSize: 14)),
                      ),
                    ]),
                  ),
          ),
          TextField(
            key: const ValueKey('username'),
            controller: _user,
            enabled: !_busy,
            autocorrect: false,
            enableSuggestions: false,
            textInputAction: TextInputAction.next,
            autofillHints: const [AutofillHints.username],
            onSubmitted: (_) => _passFocus.requestFocus(),
            style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w500),
            decoration: const InputDecoration(labelText: 'Username', prefixIcon: Icon(Icons.person_outline_rounded)),
          ),
          const SizedBox(height: 16),
          TextField(
            key: const ValueKey('password'),
            controller: _pass,
            focusNode: _passFocus,
            enabled: !_busy,
            obscureText: _obscure,
            textInputAction: TextInputAction.done,
            autofillHints: const [AutofillHints.password],
            onSubmitted: (_) => _submit(),
            style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w500),
            decoration: InputDecoration(
              labelText: 'Password',
              prefixIcon: const Icon(Icons.lock_outline_rounded),
              suffixIcon: IconButton(
                tooltip: _obscure ? 'Show password' : 'Hide password',
                icon: Icon(_obscure ? Icons.visibility_outlined : Icons.visibility_off_outlined),
                onPressed: () => setState(() => _obscure = !_obscure),
              ),
            ),
          ),
          const SizedBox(height: 10),
          Row(children: [
            Switch(
              key: const ValueKey('remember'),
              value: _remember,
              thumbIcon: WidgetStateProperty.resolveWith(
                  (s) => s.contains(WidgetState.selected) ? const Icon(Icons.check_rounded, size: 16) : null),
              onChanged: _busy ? null : (v) => setState(() => _remember = v),
            ),
            const SizedBox(width: 8),
            const Expanded(
              child: Text('Keep me signed in',
                  style: TextStyle(fontSize: 14, fontWeight: FontWeight.w600, color: Color(0xFF344054))),
            ),
            TextButton(
              onPressed: _busy ? null : () => channel.invokeMethod('forgot'),
              child: const Text('Forgot password?', style: TextStyle(fontWeight: FontWeight.w700)),
            ),
          ]),
          const SizedBox(height: 14),
          SizedBox(
            height: 56,
            child: FilledButton(
              key: const ValueKey('signin'),
              style: FilledButton.styleFrom(
                backgroundColor: navy,
                disabledBackgroundColor: navy.withValues(alpha: .85),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
                elevation: 6,
                shadowColor: navy.withValues(alpha: .4),
              ),
              onPressed: _busy ? null : _submit,
              child: _busy
                  ? const Row(mainAxisSize: MainAxisSize.min, children: [
                      SizedBox(
                          width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2.4, color: Colors.white)),
                      SizedBox(width: 12),
                      Text('Signing in…', style: TextStyle(fontSize: 16, fontWeight: FontWeight.w700, color: Colors.white)),
                    ])
                  : const Row(mainAxisSize: MainAxisSize.min, children: [
                      Text('Sign in', style: TextStyle(fontSize: 16, fontWeight: FontWeight.w700)),
                      SizedBox(width: 10),
                      Icon(Icons.arrow_forward_rounded, size: 22),
                    ]),
            ),
          ),
          if (_fp) ...[
            const SizedBox(height: 18),
            const Row(children: [
              Expanded(child: Divider(color: Color(0xFFE4E7EC))),
              Padding(
                padding: EdgeInsets.symmetric(horizontal: 12),
                child: Text('or', style: TextStyle(color: Color(0xFF98A2B3), fontWeight: FontWeight.w600)),
              ),
              Expanded(child: Divider(color: Color(0xFFE4E7EC))),
            ]),
            const SizedBox(height: 18),
            SizedBox(
              height: 56,
              child: FilledButton.tonal(
                key: const ValueKey('fingerprint'),
                style: FilledButton.styleFrom(
                  backgroundColor: blueSoft,
                  foregroundColor: blue,
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
                ),
                onPressed: _busy ? null : () => channel.invokeMethod('fingerprint'),
                child: Row(mainAxisSize: MainAxisSize.min, children: [
                  const Icon(Icons.fingerprint_rounded, size: 26),
                  const SizedBox(width: 10),
                  Flexible(
                    child: Text(
                      _fpName.isEmpty ? 'Sign in with fingerprint' : 'Fingerprint · $_fpName',
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700),
                    ),
                  ),
                ]),
              ),
            ),
          ],
          const SizedBox(height: 28),
          Text(
            _version.isEmpty ? 'YMR Marine · Survey Management System' : 'YMR Marine · Survey Management System · $_version',
            textAlign: TextAlign.center,
            style: const TextStyle(color: Color(0xFF98A2B3), fontSize: 12),
          ),
        ],
      ),
    );
  }
}

/// Navy header: logo, name, "Welcome back", the sea and a ship.
class _Header extends StatelessWidget {
  const _Header();

  @override
  Widget build(BuildContext context) {
    final top = MediaQuery.of(context).padding.top;
    return Container(
      decoration: const BoxDecoration(
        gradient: LinearGradient(begin: Alignment.topCenter, end: Alignment.bottomCenter, colors: [navy2, navy], stops: [0, .75]),
      ),
      child: Stack(children: [
        Positioned(
          right: -120,
          top: -140,
          child: Container(
            width: 380,
            height: 320,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              gradient: RadialGradient(colors: [sky.withValues(alpha: .45), sky.withValues(alpha: 0)]),
            ),
          ),
        ),
        const Positioned(left: 0, right: 0, bottom: 0, height: 150, child: CustomPaint(painter: _SeaPainter())),
        const Positioned(right: 18, bottom: 70, width: 150, height: 95, child: CustomPaint(painter: _ShipPainter())),
        Positioned(
          left: 24,
          right: 24,
          top: top + 26,
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Container(
                width: 54,
                height: 54,
                padding: const EdgeInsets.all(9),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(17),
                  boxShadow: const [BoxShadow(color: Color(0x40000000), blurRadius: 20, offset: Offset(0, 8))],
                ),
                child: Image.asset('assets/logo.png', fit: BoxFit.contain),
              ),
              const SizedBox(width: 12),
              const Flexible(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text('YMR MARINE',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(color: Colors.white, fontSize: 17, fontWeight: FontWeight.w800, letterSpacing: 1.4)),
                  SizedBox(height: 2),
                  Text('Survey Management System',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(color: Color(0xFF9FC3FF), fontSize: 12, letterSpacing: .4)),
                ]),
              ),
            ]),
            const SizedBox(height: 28),
            const Text('Welcome back',
                style: TextStyle(color: Colors.white, fontSize: 32, fontWeight: FontWeight.w800, letterSpacing: -.6, height: 1.1)),
            const SizedBox(height: 8),
            const SizedBox(
              width: 240,
              child: Text('Sign in to see your vessels, reports and surveys.',
                  style: TextStyle(color: Color(0xFFC7D7F7), fontSize: 15, height: 1.4)),
            ),
          ]),
        ),
      ]),
    );
  }
}

class _SeaPainter extends CustomPainter {
  const _SeaPainter();

  @override
  void paint(Canvas canvas, Size s) {
    Path wave(double y, double amp, double shift) {
      final p = Path()..moveTo(0, y);
      for (double x = 0; x <= s.width; x += 6) {
        p.lineTo(x, y + math.sin((x / s.width) * 2 * math.pi + shift) * amp);
      }
      return p
        ..lineTo(s.width, s.height)
        ..lineTo(0, s.height)
        ..close();
    }

    canvas.drawPath(wave(s.height * .32, 10, .3), Paint()..color = sky.withValues(alpha: .18));
    canvas.drawPath(wave(s.height * .5, 9, 1.6), Paint()..color = sky.withValues(alpha: .22));
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

/// A small container ship, in the app's colours.
class _ShipPainter extends CustomPainter {
  const _ShipPainter();

  @override
  void paint(Canvas canvas, Size s) {
    final k = s.width / 190;
    canvas.scale(k, k);
    final hull = Path()
      ..moveTo(10, 78)
      ..lineTo(172, 78)
      ..lineTo(160, 104)
      ..quadraticBezierTo(156, 110, 148, 110)
      ..lineTo(34, 110)
      ..quadraticBezierTo(26, 110, 22, 104)
      ..close();
    canvas.drawPath(hull, Paint()..color = const Color(0xFF081633));
    canvas.drawPath(
        Path()
          ..moveTo(10, 78)
          ..lineTo(172, 78)
          ..lineTo(169, 85)
          ..lineTo(13, 85)
          ..close(),
        Paint()..color = const Color(0xFFE5484D));
    void box(double x, double y, double w, double h, int c) =>
        canvas.drawRect(Rect.fromLTWH(x, y, w, h), Paint()..color = Color(c));
    box(120, 42, 34, 36, 0xFFE9EEF7);
    box(124, 48, 26, 6, 0xFF0B1E46);
    box(138, 26, 8, 16, 0xFFF59E0B);
    box(30, 58, 22, 20, 0xFF3B82F6);
    box(54, 58, 22, 20, 0xFFF97316);
    box(78, 58, 22, 20, 0xFF22C55E);
    box(42, 40, 22, 18, 0xFFEAB308);
    box(66, 40, 22, 18, 0xFF60A5FA);
    box(96, 44, 20, 14, 0xFFEF4444);
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}
