package com.kivutracker.app;

import android.content.Context;
import android.content.SharedPreferences;
import android.util.Log;

import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;

/**
 * Registers (and re-registers) this device's FCM token with the platform so
 * the server knows where to send alerts:
 *
 * <pre>POST {server}/api/push/device   {"token":"&lt;fcm token&gt;"}</pre>
 *
 * Authentication reuses the console session the WebView holds — MainActivity
 * mirrors localStorage's {@code kt_token} into prefs as {@code api_token}
 * after every page load. All calls are fire-and-forget on a background thread.
 */
public final class DeviceReporter {

    private static final String TAG = "KivuPush";
    private DeviceReporter() {}

    public static void registerAsync(final Context ctx, final String fcmToken) {
        new Thread(() -> register(ctx, fcmToken), "kivu-push-register").start();
    }

    private static void register(Context ctx, final String fcmToken) {
        SharedPreferences p = ctx.getSharedPreferences(MainActivity.PREFS, Context.MODE_PRIVATE);
        String base = MainActivity.serverUrl(ctx);
        String auth = p.getString("api_token", "");
        if (base.isEmpty() || auth.isEmpty() || fcmToken == null || fcmToken.isEmpty()) {
            Log.i(TAG, "register skipped: server=" + !base.isEmpty() + " session=" + !auth.isEmpty()
                    + " token=" + (fcmToken != null && !fcmToken.isEmpty()));
            return;
        }
        post(base + "/api/push/device", auth, fcmToken);
    }

    private static void post(String url, String auth, String fcmToken) {
        HttpURLConnection c = null;
        try {
            c = (HttpURLConnection) new URL(url).openConnection();
            c.setRequestMethod("POST");
            c.setConnectTimeout(8000);
            c.setReadTimeout(8000);
            c.setRequestProperty("Content-Type", "application/json");
            c.setRequestProperty("X-Auth-Token", auth);
            c.setDoOutput(true);
            byte[] body = ("{\"token\":\"" + fcmToken + "\"}").getBytes(StandardCharsets.UTF_8);
            try (OutputStream os = c.getOutputStream()) { os.write(body); }
            int code = c.getResponseCode();
            Log.i(TAG, "register " + url + " -> HTTP " + code);
        } catch (Exception e) {
            Log.w(TAG, "register failed: " + e);
        } finally {
            if (c != null) c.disconnect();
        }
    }
}
