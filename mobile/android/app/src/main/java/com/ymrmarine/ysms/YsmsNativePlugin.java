package com.ymrmarine.ysms;

import android.os.Build;
import androidx.annotation.NonNull;
import androidx.biometric.BiometricManager;
import androidx.biometric.BiometricPrompt;
import androidx.core.content.ContextCompat;
import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;

/**
 * Phone features the YSMS screens (app.php) ask for:
 *  - fingerprint / face unlock ("App lock" in Profile): biometricStatus() and biometricVerify();
 *    the phone's PIN / pattern is accepted as a fallback on Android 11+;
 *  - pushReady(): whether this build has a Firebase config, so push notifications can register.
 * Nothing is stored here; the sign-in itself stays the website session.
 */
@CapacitorPlugin(name = "YsmsNative")
public class YsmsNativePlugin extends Plugin {

    private int authenticators() {
        return Build.VERSION.SDK_INT >= Build.VERSION_CODES.R
            ? BiometricManager.Authenticators.BIOMETRIC_WEAK | BiometricManager.Authenticators.DEVICE_CREDENTIAL
            : BiometricManager.Authenticators.BIOMETRIC_WEAK;
    }

    @PluginMethod
    public void biometricStatus(PluginCall call) {
        int s = BiometricManager.from(getContext()).canAuthenticate(authenticators());
        JSObject r = new JSObject();
        r.put("available", s == BiometricManager.BIOMETRIC_SUCCESS);
        r.put(
            "reason",
            s == BiometricManager.BIOMETRIC_SUCCESS ? "" : s == BiometricManager.BIOMETRIC_ERROR_NONE_ENROLLED ? "not_enrolled" : "unavailable"
        );
        call.resolve(r);
    }

    @PluginMethod
    public void biometricVerify(PluginCall call) {
        String title = call.getString("title", "Unlock YMR YSMS");
        String subtitle = call.getString("subtitle", "Use your fingerprint");
        getActivity()
            .runOnUiThread(() -> {
                BiometricPrompt.PromptInfo.Builder info = new BiometricPrompt.PromptInfo.Builder()
                    .setTitle(title)
                    .setSubtitle(subtitle)
                    .setAllowedAuthenticators(authenticators());
                if (Build.VERSION.SDK_INT < Build.VERSION_CODES.R) info.setNegativeButtonText("Cancel");
                BiometricPrompt prompt = new BiometricPrompt(
                    getActivity(),
                    ContextCompat.getMainExecutor(getContext()),
                    new BiometricPrompt.AuthenticationCallback() {
                        @Override
                        public void onAuthenticationSucceeded(@NonNull BiometricPrompt.AuthenticationResult result) {
                            JSObject r = new JSObject();
                            r.put("ok", true);
                            call.resolve(r);
                        }

                        @Override
                        public void onAuthenticationError(int code, @NonNull CharSequence msg) {
                            call.reject(msg.toString(), String.valueOf(code));
                        }
                        // onAuthenticationFailed (finger not recognised): the prompt stays open and lets them retry.
                    }
                );
                try {
                    prompt.authenticate(info.build());
                } catch (Exception e) {
                    call.reject("Fingerprint unlock is not available", "unavailable");
                }
            });
    }

    @PluginMethod
    public void pushReady(PluginCall call) {
        // The google-services plugin adds this string only when the build had a google-services.json.
        int id = getContext().getResources().getIdentifier("google_app_id", "string", getContext().getPackageName());
        JSObject r = new JSObject();
        r.put("ready", id != 0);
        call.resolve(r);
    }
}
