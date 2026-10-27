package com.ymrmarine.ysms;

import android.content.Context;
import android.content.SharedPreferences;
import android.os.Build;
import android.security.keystore.KeyGenParameterSpec;
import android.security.keystore.KeyPermanentlyInvalidatedException;
import android.security.keystore.KeyProperties;
import android.util.Base64;
import androidx.annotation.NonNull;
import androidx.biometric.BiometricManager;
import androidx.biometric.BiometricPrompt;
import androidx.core.content.ContextCompat;
import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;
import java.nio.charset.StandardCharsets;
import java.security.KeyStore;
import javax.crypto.Cipher;
import javax.crypto.KeyGenerator;
import javax.crypto.SecretKey;
import javax.crypto.spec.GCMParameterSpec;

/**
 * Phone features the YSMS screens (app.php) ask for:
 *  - fingerprint / face unlock ("App lock" in Profile): biometricStatus() and biometricVerify();
 *    the phone's PIN / pattern is accepted as a fallback on Android 11+;
 *  - fingerprint login: bioKeySave / bioKeyRead / bioKeyHas / bioKeyClear keep the server's device
 *    key (ajax/app_bio_login.php) encrypted with an Android Keystore key that only a strong
 *    biometric unlocks, and that a newly added fingerprint invalidates;
 *  - pushReady(): whether this build has a Firebase config, so push notifications can register.
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

    /* ---------- Fingerprint login: device key kept under the Keystore ---------- */
    private static final String KEY_ALIAS = "ysms_bio_login";
    private static final String PREFS = "ysms_bio_login";

    private SharedPreferences prefs() {
        return getContext().getSharedPreferences(PREFS, Context.MODE_PRIVATE);
    }

    private SecretKey keystoreKey(boolean create) throws Exception {
        KeyStore ks = KeyStore.getInstance("AndroidKeyStore");
        ks.load(null);
        if (ks.containsAlias(KEY_ALIAS)) return (SecretKey) ks.getKey(KEY_ALIAS, null);
        if (!create) return null;
        KeyGenParameterSpec.Builder b = new KeyGenParameterSpec.Builder(KEY_ALIAS, KeyProperties.PURPOSE_ENCRYPT | KeyProperties.PURPOSE_DECRYPT)
            .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
            .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
            .setUserAuthenticationRequired(true)
            .setInvalidatedByBiometricEnrollment(true);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) b.setUserAuthenticationParameters(0, KeyProperties.AUTH_BIOMETRIC_STRONG);
        KeyGenerator kg = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore");
        kg.init(b.build());
        return kg.generateKey();
    }

    private void clearKey() {
        prefs().edit().clear().apply();
        try {
            KeyStore ks = KeyStore.getInstance("AndroidKeyStore");
            ks.load(null);
            if (ks.containsAlias(KEY_ALIAS)) ks.deleteEntry(KEY_ALIAS);
        } catch (Exception ignored) {
            // nothing to delete
        }
    }

    /** Fingerprint prompt bound to the cipher; the cipher is usable only after a strong biometric. */
    private void cryptoPrompt(PluginCall call, Cipher cipher, String title, String subtitle, CryptoDone done) {
        getActivity()
            .runOnUiThread(() -> {
                BiometricPrompt.PromptInfo info = new BiometricPrompt.PromptInfo.Builder()
                    .setTitle(title)
                    .setSubtitle(subtitle)
                    .setAllowedAuthenticators(BiometricManager.Authenticators.BIOMETRIC_STRONG)
                    .setNegativeButtonText("Use password")
                    .build();
                BiometricPrompt prompt = new BiometricPrompt(
                    getActivity(),
                    ContextCompat.getMainExecutor(getContext()),
                    new BiometricPrompt.AuthenticationCallback() {
                        @Override
                        public void onAuthenticationSucceeded(@NonNull BiometricPrompt.AuthenticationResult result) {
                            try {
                                done.run(result.getCryptoObject().getCipher());
                            } catch (Exception e) {
                                call.reject("Could not read the fingerprint key", "crypto");
                            }
                        }

                        @Override
                        public void onAuthenticationError(int code, @NonNull CharSequence msg) {
                            call.reject(msg.toString(), String.valueOf(code));
                        }
                    }
                );
                try {
                    prompt.authenticate(info, new BiometricPrompt.CryptoObject(cipher));
                } catch (Exception e) {
                    call.reject("Fingerprint login is not available", "unavailable");
                }
            });
    }

    private interface CryptoDone {
        void run(Cipher cipher) throws Exception;
    }

    @PluginMethod
    public void bioKeyHas(PluginCall call) {
        boolean has = false;
        try {
            has = prefs().contains("ct") && keystoreKey(false) != null;
        } catch (Exception ignored) {
            has = false;
        }
        JSObject r = new JSObject();
        r.put("has", has);
        r.put("strong", BiometricManager.from(getContext()).canAuthenticate(BiometricManager.Authenticators.BIOMETRIC_STRONG) == BiometricManager.BIOMETRIC_SUCCESS);
        call.resolve(r);
    }

    @PluginMethod
    public void bioKeySave(PluginCall call) {
        String secret = call.getString("key", "");
        if (secret == null || secret.isEmpty()) {
            call.reject("No key", "args");
            return;
        }
        try {
            clearKey();
            Cipher cipher = Cipher.getInstance("AES/GCM/NoPadding");
            cipher.init(Cipher.ENCRYPT_MODE, keystoreKey(true));
            cryptoPrompt(call, cipher, "Turn on fingerprint login", "Confirm with your fingerprint", (c) -> {
                byte[] ct = c.doFinal(secret.getBytes(StandardCharsets.UTF_8));
                prefs()
                    .edit()
                    .putString("ct", Base64.encodeToString(ct, Base64.NO_WRAP))
                    .putString("iv", Base64.encodeToString(c.getIV(), Base64.NO_WRAP))
                    .apply();
                JSObject r = new JSObject();
                r.put("ok", true);
                call.resolve(r);
            });
        } catch (Exception e) {
            clearKey();
            call.reject("Fingerprint login is not available on this phone", "unavailable");
        }
    }

    @PluginMethod
    public void bioKeyRead(PluginCall call) {
        String ct = prefs().getString("ct", null), iv = prefs().getString("iv", null);
        if (ct == null || iv == null) {
            call.reject("Fingerprint login is not set up", "none");
            return;
        }
        try {
            SecretKey key = keystoreKey(false);
            if (key == null) throw new KeyPermanentlyInvalidatedException();
            Cipher cipher = Cipher.getInstance("AES/GCM/NoPadding");
            cipher.init(Cipher.DECRYPT_MODE, key, new GCMParameterSpec(128, Base64.decode(iv, Base64.NO_WRAP)));
            cryptoPrompt(call, cipher, "Login to YMR YSMS", "Use your fingerprint", (c) -> {
                JSObject r = new JSObject();
                r.put("key", new String(c.doFinal(Base64.decode(ct, Base64.NO_WRAP)), StandardCharsets.UTF_8));
                call.resolve(r);
            });
        } catch (KeyPermanentlyInvalidatedException e) {
            // A fingerprint was added or removed on the phone: set it up again after a password login.
            clearKey();
            call.reject("Fingerprints on this phone changed. Sign in with your password and turn fingerprint login on again.", "invalidated");
        } catch (Exception e) {
            call.reject("Fingerprint login is not available", "unavailable");
        }
    }

    @PluginMethod
    public void bioKeyClear(PluginCall call) {
        clearKey();
        call.resolve();
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
