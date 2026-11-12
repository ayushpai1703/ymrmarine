package com.ymrmarine.ysms;

import android.content.Context;
import android.os.Bundle;
import androidx.annotation.NonNull;
import androidx.annotation.Nullable;
import io.flutter.embedding.android.FlutterActivity;
import io.flutter.embedding.engine.FlutterEngine;

/** The Flutter login screen (see FlutterLogin). Uses the engine started with the app and keeps it. */
public class LoginActivity extends FlutterActivity {

    @Override
    protected void onCreate(@Nullable Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        FlutterLogin.attached(this);
    }

    @Nullable
    @Override
    public FlutterEngine provideFlutterEngine(@NonNull Context context) {
        return FlutterLogin.engine(context);
    }

    @Override
    public boolean shouldDestroyEngineWithHost() {
        return false;
    }

    @Override
    protected void onDestroy() {
        FlutterLogin.detached(this);
        super.onDestroy();
    }
}
