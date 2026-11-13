// Screens not made in Flutter yet open the web app's own screen (app.php#/…) or a website page inside
// the app, signed in with the same cookies. Leaving it (Back, or the page going back to a main tab)
// returns to the Flutter screens.
import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_inappwebview/flutter_inappwebview.dart';

import '../api.dart';
import '../config.dart';
import '../ui.dart';

/// Opens a Flutter screen for a web route (e.g. '/v/12' → the vessel screen); true when it did.
typedef NativeRoute = bool Function(BuildContext context, String route);

class WebScreen extends StatefulWidget {
  const WebScreen({super.key, required this.path, this.title});
  final String path;
  final String? title;

  static NativeRoute? nativeRoute;

  @override
  State<WebScreen> createState() => _WebScreenState();
}

const _mainTabs = {'home', 'vessels', 'reports', 'completed', 'profile'};

class _WebScreenState extends State<WebScreen> {
  InAppWebViewController? _c;
  double _progress = 0;
  bool _closing = false;
  late final String _startRoute = _routeOf(Uri.parse(Cfg.url(widget.path)));

  String _routeOf(Uri u) {
    if (!u.path.endsWith('app.php')) return '';
    final f = u.fragment.replaceFirst(RegExp(r'^/?'), '');
    return f;
  }

  void _close() {
    if (_closing || !mounted) return;
    _closing = true;
    Navigator.of(context).maybePop();
  }

  Future<void> _onUrl(WebUri? url) async {
    if (url == null || _closing) return;
    final u = Uri.parse(url.toString());
    final route = _routeOf(u);
    if (route.isEmpty || route == _startRoute) return;
    if (route == 'login') {
      // The web app found no sign-in: the Flutter login screen takes over.
      _close();
      Api.i.markLost();
      return;
    }
    if (_mainTabs.contains(route)) {
      _close();
      return;
    }
    final nr = WebScreen.nativeRoute;
    if (nr != null && mounted && nr(context, '/$route')) {
      // shown as a Flutter screen; the web screen goes back to where it was
      unawaited(_c?.goBack());
    }
  }

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) async {
        if (didPop) return;
        final c = _c;
        if (!_closing && c != null && await c.canGoBack()) {
          await c.goBack();
        } else {
          _closing = true;
          if (context.mounted) Navigator.of(context).pop();
        }
      },
      child: Scaffold(
        backgroundColor: pageColor(context),
        body: SafeArea(
          bottom: false,
          child: Stack(
            children: [
              InAppWebView(
                initialUrlRequest: URLRequest(url: WebUri(Cfg.url(widget.path))),
                initialSettings: InAppWebViewSettings(
                  applicationNameForUserAgent: Cfg.uaSuffix,
                  javaScriptEnabled: true,
                  domStorageEnabled: true,
                  useShouldOverrideUrlLoading: true,
                  useOnDownloadStart: true,
                  allowsBackForwardNavigationGestures: true,
                  supportZoom: false,
                  transparentBackground: true,
                  mediaPlaybackRequiresUserGesture: true,
                  allowFileAccessFromFileURLs: false,
                  allowUniversalAccessFromFileURLs: false,
                ),
                onWebViewCreated: (c) => _c = c,
                onProgressChanged: (_, p) => setState(() => _progress = p / 100),
                onUpdateVisitedHistory: (_, url, _) => _onUrl(url),
                shouldOverrideUrlLoading: (c, nav) async {
                  final url = nav.request.url;
                  if (url == null) return NavigationActionPolicy.ALLOW;
                  final base = Uri.parse(Cfg.base);
                  final same = (url.scheme == 'https' || url.scheme == 'http') && url.host == base.host;
                  if (same) return NavigationActionPolicy.ALLOW;
                  // WhatsApp, email, phone, other sites: the phone's own apps.
                  unawaited(openExternal(url.toString()).catchError((_) {}));
                  return NavigationActionPolicy.CANCEL;
                },
                onDownloadStartRequest: (c, req) {
                  downloadAndOpen(context, req.url.toString(), name: req.suggestedFilename);
                },
              ),
              if (_progress < 1)
                Positioned(
                  left: 0,
                  right: 0,
                  top: 0,
                  child: LinearProgressIndicator(value: _progress == 0 ? null : _progress, minHeight: 3),
                ),
            ],
          ),
        ),
      ),
    );
  }
}
