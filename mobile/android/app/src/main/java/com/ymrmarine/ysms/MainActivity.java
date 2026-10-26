package com.ymrmarine.ysms;

import android.Manifest;
import android.app.DownloadManager;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.content.IntentFilter;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Environment;
import android.webkit.CookieManager;
import android.webkit.URLUtil;
import android.webkit.WebView;
import android.widget.Toast;
import androidx.core.content.ContextCompat;
import com.getcapacitor.BridgeActivity;

/**
 * YSMS app shell. The web app runs from the server set in capacitor.config.json; this
 * activity adds what a WebView lacks on its own:
 *  - file downloads (reports, Excel exports, documents) go through Android's DownloadManager
 *    with the signed-in session cookie, land in Downloads and open when finished;
 *  - files the page builds itself (blob: links, e.g. the Word report generator) are saved by
 *    the FileSaver plugin, see FileSaverPlugin.java.
 */
public class MainActivity extends BridgeActivity {

    private long lastDownloadId = -1;

    private final BroadcastReceiver downloadDone = new BroadcastReceiver() {
        @Override
        public void onReceive(Context context, Intent intent) {
            long id = intent.getLongExtra(DownloadManager.EXTRA_DOWNLOAD_ID, -1);
            if (id == -1 || id != lastDownloadId) return;
            DownloadManager dm = (DownloadManager) getSystemService(Context.DOWNLOAD_SERVICE);
            Uri uri = dm.getUriForDownloadedFile(id);
            if (uri == null) {
                Toast.makeText(MainActivity.this, "Download failed", Toast.LENGTH_LONG).show();
                return;
            }
            FileSaverPlugin.openFile(MainActivity.this, uri, dm.getMimeTypeForDownloadedFile(id));
        }
    };

    @Override
    public void onCreate(Bundle savedInstanceState) {
        registerPlugin(FileSaverPlugin.class);
        super.onCreate(savedInstanceState);

        WebView webView = getBridge().getWebView();
        webView.setDownloadListener((url, userAgent, contentDisposition, mimeType, contentLength) -> {
            if (url == null || !(url.startsWith("https://") || url.startsWith("http://"))) {
                // blob:/data: links are saved from the page through the FileSaver plugin.
                return;
            }
            startDownload(url, userAgent, contentDisposition, mimeType);
        });

        IntentFilter filter = new IntentFilter(DownloadManager.ACTION_DOWNLOAD_COMPLETE);
        ContextCompat.registerReceiver(this, downloadDone, filter, ContextCompat.RECEIVER_EXPORTED);
    }

    @Override
    public void onDestroy() {
        try {
            unregisterReceiver(downloadDone);
        } catch (IllegalArgumentException ignored) {
            // not registered
        }
        super.onDestroy();
    }

    private void startDownload(String url, String userAgent, String contentDisposition, String mimeType) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.Q
                && ContextCompat.checkSelfPermission(this, Manifest.permission.WRITE_EXTERNAL_STORAGE) != PackageManager.PERMISSION_GRANTED) {
            requestPermissions(new String[] { Manifest.permission.WRITE_EXTERNAL_STORAGE }, 41);
            Toast.makeText(this, "Allow storage access, then tap download again", Toast.LENGTH_LONG).show();
            return;
        }
        try {
            String fileName = URLUtil.guessFileName(url, contentDisposition, mimeType);
            DownloadManager.Request request = new DownloadManager.Request(Uri.parse(url));
            String cookies = CookieManager.getInstance().getCookie(url);
            if (cookies != null) request.addRequestHeader("Cookie", cookies);
            request.addRequestHeader("User-Agent", userAgent);
            if (mimeType != null) request.setMimeType(mimeType);
            request.setTitle(fileName);
            request.setDescription("YMR YSMS");
            request.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);
            request.setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, fileName);
            DownloadManager dm = (DownloadManager) getSystemService(Context.DOWNLOAD_SERVICE);
            lastDownloadId = dm.enqueue(request);
            Toast.makeText(this, "Downloading " + fileName + "…", Toast.LENGTH_SHORT).show();
        } catch (Exception e) {
            Toast.makeText(this, "Could not download this file", Toast.LENGTH_LONG).show();
        }
    }
}
