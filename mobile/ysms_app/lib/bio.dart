// Fingerprint login and small settings kept on the phone.
//   Turn on (Profile): the phone's fingerprint is asked, then ajax/app_bio_login.php (action=enroll) gives
//   this phone a login key, kept in the phone's secure storage (Android Keystore / iOS Keychain).
//   Login: the fingerprint is asked again, and the key signs in (action=login).
import 'dart:math';

import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:local_auth/local_auth.dart';

import 'api.dart';

class Prefs {
  static const _st = FlutterSecureStorage();
  static final Map<String, String?> _mem = {};

  /// Tests and the web preview: kept in memory only.
  static bool memoryOnly = kIsWeb;

  static Future<String?> read(String k) async {
    if (memoryOnly) return _mem[k];
    try {
      return await _st.read(key: k).timeout(const Duration(seconds: 5));
    } catch (_) {
      return null;
    }
  }

  static Future<void> write(String k, String? v) async {
    if (memoryOnly) {
      _mem[k] = v;
      return;
    }
    try {
      if (v == null) {
        await _st.delete(key: k).timeout(const Duration(seconds: 5));
      } else {
        await _st.write(key: k, value: v).timeout(const Duration(seconds: 5));
      }
    } catch (_) {}
  }
}

class Bio {
  static final _auth = LocalAuthentication();

  static Future<bool> available() async {
    if (kIsWeb) return false;
    try {
      return await _auth.isDeviceSupported() && await _auth.canCheckBiometrics;
    } catch (_) {
      return false;
    }
  }

  static Future<String?> key() => Prefs.read('bioKey');
  static Future<String> name() async => await Prefs.read('bioName') ?? '';

  /// Ask for the fingerprint (or the phone's PIN). False when cancelled.
  static Future<bool> prompt(String reason) async {
    try {
      return await _auth.authenticate(localizedReason: reason, persistAcrossBackgrounding: true);
    } catch (_) {
      return false;
    }
  }

  static Future<String> device() async {
    var d = await Prefs.read('device');
    if (d == null || d.isEmpty) {
      final r = Random.secure();
      d = List.generate(16, (_) => r.nextInt(256).toRadixString(16).padLeft(2, '0')).join();
      await Prefs.write('device', d);
    }
    return d;
  }

  /// Turn fingerprint login on for the signed-in user. Throws ApiError with the reason.
  static Future<void> enroll() async {
    if (!await prompt('Confirm your fingerprint to turn on fingerprint login')) {
      throw ApiError(-1, 'Cancelled');
    }
    final dev = await device();
    final r = await Api.i.post('ajax/app_bio_login.php', (csrf) => {'action': 'enroll', 'device': dev, 'csrf_token': csrf});
    final j = Api.i.json(r);
    if (r.statusCode != 200 || j == null || j['success'] != true || _s(j['key']).isEmpty) {
      throw ApiError(r.statusCode ?? 500, _s(j?['message']).isNotEmpty ? _s(j?['message']) : 'Could not turn on fingerprint login.');
    }
    await Prefs.write('bioKey', _s(j['key']));
    await Prefs.write('bioName', _s(Api.i.me?['name']));
  }

  static Future<void> disable() async {
    final dev = await device();
    try {
      await Api.i.post('ajax/app_bio_login.php', (csrf) => {'action': 'disable', 'device': dev, 'csrf_token': csrf});
    } catch (_) {}
    await clear();
  }

  static Future<void> clear() async {
    await Prefs.write('bioKey', null);
    await Prefs.write('bioName', null);
  }
}

String _s(dynamic v) => v == null ? '' : '$v';
