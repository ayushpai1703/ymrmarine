import 'package:flutter/services.dart' show appFlavor;

/// Which server the app talks to. Two Android / iOS apps are built from this code:
///   staging → the staging server and its data   (app id com.ymrmarine.ysms.staging, "YSMS Staging")
///   live    → the live server                   (app id com.ymrmarine.ysms, "YMR YSMS")
/// `--dart-define=YSMS_SERVER=https://…/YSMS/` overrides the address (tests, a local server).
class Cfg {
  static const _override = String.fromEnvironment('YSMS_SERVER');
  static const _flavorDefine = String.fromEnvironment('YSMS_FLAVOR');

  static const stagingBase = 'https://ymrmarine-staging-23712461887.asia-south1.run.app/YSMS/';
  static const liveBase = 'https://ymrmarine-23712461887.asia-south1.run.app/YSMS/';

  static String get flavor => _flavorDefine.isNotEmpty ? _flavorDefine : (appFlavor ?? 'staging');
  static bool get isLive => flavor == 'live';

  /// Base address of the YSMS folder, ending with "/". Empty on the web preview (same site).
  static String get base => _override.isNotEmpty ? _override : (isLive ? liveBase : stagingBase);

  static const version = '2.0';

  /// The server treats requests carrying "YSMSApp/" as the app (fingerprint login, app look of pages).
  static const userAgent = 'Mozilla/5.0 (Linux; Android) YSMSApp/$version Flutter';
  static const uaSuffix = 'YSMSApp/$version';

  static String url(String path) => Uri.parse(base).resolve(path).toString();
}
