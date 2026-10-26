package com.ymrmarine.ysms;

import android.content.ActivityNotFoundException;
import android.content.ContentResolver;
import android.content.ContentValues;
import android.content.Context;
import android.content.Intent;
import android.net.Uri;
import android.os.Build;
import android.os.Environment;
import android.provider.MediaStore;
import android.util.Base64;
import android.widget.Toast;
import androidx.core.content.FileProvider;
import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;
import java.io.File;
import java.io.FileOutputStream;
import java.io.OutputStream;

/**
 * Saves a file the page made itself (a blob: or data: link with a download name, e.g. the
 * Word report generator) into Downloads and opens it. Called from YSMS/assets/js/app-shell.js:
 *   Capacitor.Plugins.FileSaver.save({ name, mime, data })   // data = base64
 */
@CapacitorPlugin(name = "FileSaver")
public class FileSaverPlugin extends Plugin {

    private static final int MAX_BYTES = 60 * 1024 * 1024;

    @PluginMethod
    public void save(PluginCall call) {
        String name = safeName(call.getString("name", "download"));
        String mime = call.getString("mime", "application/octet-stream");
        String data = call.getString("data", "");
        byte[] bytes;
        try {
            bytes = Base64.decode(data, Base64.DEFAULT);
        } catch (IllegalArgumentException e) {
            call.reject("Bad file data");
            return;
        }
        if (bytes.length == 0 || bytes.length > MAX_BYTES) {
            call.reject("File is empty or too large");
            return;
        }
        Context ctx = getContext();
        try {
            Uri uri;
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                ContentValues values = new ContentValues();
                values.put(MediaStore.Downloads.DISPLAY_NAME, name);
                values.put(MediaStore.Downloads.MIME_TYPE, mime);
                values.put(MediaStore.Downloads.IS_PENDING, 1);
                ContentResolver resolver = ctx.getContentResolver();
                uri = resolver.insert(MediaStore.Downloads.EXTERNAL_CONTENT_URI, values);
                if (uri == null) throw new IllegalStateException("no uri");
                try (OutputStream out = resolver.openOutputStream(uri)) {
                    if (out == null) throw new IllegalStateException("no stream");
                    out.write(bytes);
                }
                values.clear();
                values.put(MediaStore.Downloads.IS_PENDING, 0);
                resolver.update(uri, values, null, null);
            } else {
                File dir = Environment.getExternalStoragePublicDirectory(Environment.DIRECTORY_DOWNLOADS);
                if (!dir.exists() && !dir.mkdirs()) throw new IllegalStateException("no downloads folder");
                File file = uniqueFile(dir, name);
                try (FileOutputStream out = new FileOutputStream(file)) {
                    out.write(bytes);
                }
                uri = FileProvider.getUriForFile(ctx, ctx.getPackageName() + ".fileprovider", file);
            }
            Toast.makeText(ctx, "Saved to Downloads: " + name, Toast.LENGTH_SHORT).show();
            openFile(ctx, uri, mime);
            JSObject ret = new JSObject();
            ret.put("name", name);
            call.resolve(ret);
        } catch (Exception e) {
            call.reject("Could not save the file");
        }
    }

    static void openFile(Context ctx, Uri uri, String mime) {
        Intent view = new Intent(Intent.ACTION_VIEW);
        view.setDataAndType(uri, mime != null ? mime : "*/*");
        view.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION | Intent.FLAG_ACTIVITY_NEW_TASK);
        try {
            ctx.startActivity(view);
        } catch (ActivityNotFoundException e) {
            Toast.makeText(ctx, "Saved in Downloads (no app found to open it)", Toast.LENGTH_LONG).show();
        }
    }

    private static String safeName(String name) {
        String n = name == null ? "" : name.replaceAll("[\\\\/:*?\"<>|\\p{Cntrl}]", "_").trim();
        if (n.isEmpty() || n.equals(".") || n.equals("..")) n = "download";
        return n.length() > 150 ? n.substring(n.length() - 150) : n;
    }

    private static File uniqueFile(File dir, String name) {
        File f = new File(dir, name);
        int dot = name.lastIndexOf('.');
        String base = dot > 0 ? name.substring(0, dot) : name;
        String ext = dot > 0 ? name.substring(dot) : "";
        for (int i = 1; f.exists() && i < 1000; i++) f = new File(dir, base + " (" + i + ")" + ext);
        return f;
    }
}
