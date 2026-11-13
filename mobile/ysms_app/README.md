# YMR YSMS — Flutter app (Android and iOS)

Two apps are built from this code (GitHub Actions → "Flutter app"):

| App | App id | Name | Server |
|---|---|---|---|
| staging | `com.ymrmarine.ysms.staging` | YSMS Staging | staging server and its data |
| live | `com.ymrmarine.ysms` | YMR YSMS | live server (installs over the earlier live test APK) |

The server address is in `lib/config.dart`; `--dart-define=YSMS_SERVER=https://…/YSMS/` overrides it.

## Screens

Made in Flutter: login (fingerprint login too), home dashboard, Pending Vessels / Pending Reports /
Completed / Cancelled lists, search, vessel details (latest update, documents, share), upload, profile.

The other screens (team chat, inbox, recovery, assign vessel, admin tools, generators …) open the web
app's own screen inside the app (`lib/screens/web.dart`), signed in with the same cookies. They move to
Flutter screen by screen.

## How it talks to the server

Read-only JSON from `app_api.php` (the same rules as the website); every change goes through the
existing pages (`login.php`, `vessel_detail.php`, `ajax/upload_handler.php` …), so the server's own
permission checks, CSRF checks and notifications apply unchanged. No server change is needed.

## Build locally

```
flutter pub get
flutter test
flutter build apk --release --flavor staging      # or --flavor live
flutter build ios --release --no-codesign --dart-define=YSMS_FLAVOR=live
```

Android test builds are signed with `android/app/test-debug.keystore` (test key only; the Play Store
key is never kept in the repository). iOS builds need an Apple Developer account to be signed.
