package com.ymrmarine.ysms;

import android.app.Activity;
import android.content.Context;
import android.content.Intent;
import com.getcapacitor.JSObject;
import io.flutter.embedding.engine.FlutterEngine;
import io.flutter.embedding.engine.FlutterEngineCache;
import io.flutter.embedding.engine.dart.DartExecutor;
import io.flutter.plugin.common.MethodChannel;
import java.lang.ref.WeakReference;
import java.util.HashMap;
import java.util.Iterator;
import java.util.Map;

/**
 * The login screen is a Flutter screen (mobile/flutter_login); every other screen stays the web app.
 *
 * The web app (app.php) opens it through YsmsNativePlugin.flutterLoginOpen and does the signing in
 * itself, so the session cookie stays in the WebView exactly as before:
 *   Flutter "submit" / "fingerprint" / "forgot"  →  event "flutterLogin" to the page
 *   page → flutterLoginResult({ok}) →  close this screen, or show the error in it (it stays open).
 * One Flutter engine is started with the app and kept, so the screen opens without a delay.
 */
public final class FlutterLogin {

    static final String ENGINE_ID = "ysms_login";
    private static final String CHANNEL = "ysms/login";

    private static MethodChannel channel;
    private static Map<String, Object> state = new HashMap<>();
    private static WeakReference<Activity> screen = new WeakReference<>(null);

    private FlutterLogin() {}

    /** The engine (started once, kept for the life of the app). */
    static synchronized FlutterEngine engine(Context ctx) {
        FlutterEngine e = FlutterEngineCache.getInstance().get(ENGINE_ID);
        if (e != null) return e;
        e = new FlutterEngine(ctx.getApplicationContext());
        e.getDartExecutor().executeDartEntrypoint(DartExecutor.DartEntrypoint.createDefault());
        FlutterEngineCache.getInstance().put(ENGINE_ID, e);
        channel = new MethodChannel(e.getDartExecutor().getBinaryMessenger(), CHANNEL);
        channel.setMethodCallHandler((call, result) -> {
            switch (call.method) {
                case "state":
                    result.success(state);
                    return;
                case "submit": {
                    JSObject ev = new JSObject();
                    ev.put("action", "login");
                    ev.put("username", (String) call.argument("username"));
                    ev.put("password", (String) call.argument("password"));
                    Boolean rem = call.argument("remember");
                    ev.put("remember", rem != null && rem);
                    YsmsNativePlugin.emitLogin(ev);
                    result.success(null);
                    return;
                }
                case "fingerprint":
                case "forgot": {
                    JSObject ev = new JSObject();
                    ev.put("action", call.method);
                    close();
                    YsmsNativePlugin.emitLogin(ev);
                    result.success(null);
                    return;
                }
                case "exit": {
                    // Back on the login screen leaves the app (nothing to go back to before signing in).
                    Activity a = screen.get();
                    if (a != null) a.moveTaskToBack(true);
                    result.success(null);
                    return;
                }
                default:
                    result.notImplemented();
            }
        });
        return e;
    }

    /** Open the login screen with what it should show (error, username, fingerprint button, version). */
    static void open(Activity from, JSObject opts) {
        Map<String, Object> s = new HashMap<>();
        Iterator<String> keys = opts.keys();
        while (keys.hasNext()) {
            String k = keys.next();
            s.put(k, opts.opt(k));
        }
        state = s;
        engine(from);
        if (channel != null) channel.invokeMethod("show", state);
        if (screen.get() == null) {
            Intent i = new Intent(from, LoginActivity.class);
            i.addFlags(Intent.FLAG_ACTIVITY_NO_ANIMATION);
            from.startActivity(i);
            from.overridePendingTransition(0, 0);
        }
    }

    /** The page's answer to "submit": close on success, else the error on the Flutter screen. */
    static void result(boolean ok, String error) {
        if (ok) {
            close();
            return;
        }
        if (channel != null) channel.invokeMethod("error", error == null ? "" : error);
    }

    static void close() {
        Activity a = screen.get();
        if (a != null) {
            a.finish();
            a.overridePendingTransition(0, android.R.anim.fade_out);
        }
        screen = new WeakReference<>(null);
    }

    static void attached(Activity a) {
        screen = new WeakReference<>(a);
    }

    static void detached(Activity a) {
        if (screen.get() == a) screen = new WeakReference<>(null);
    }
}
