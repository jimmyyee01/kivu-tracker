package com.kivutracker.app;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.app.Service;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.location.Location;
import android.location.LocationListener;
import android.location.LocationManager;
import android.os.Build;
import android.os.Bundle;
import android.os.IBinder;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.BufferedReader;
import java.io.InputStreamReader;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.text.SimpleDateFormat;
import java.util.Date;
import java.util.Locale;
import java.util.TimeZone;

/**
 * Tracker mode: reports this phone's own position to the platform exactly like a
 * hardware tracker, using the public HTTP ingest contract
 *
 *   POST /api/ingest { unique_id, password, points: [ { ts, lat, lon, speed, ... } ] }
 *
 * The unit must already exist (Administration → Units) with the same unique ID and
 * device password. Runs as a foreground service so it survives the screen going off.
 */
public class TrackerService extends Service implements LocationListener {

    private static final String CHANNEL = "kivu-tracker";
    private static final int NOTIFY_ID = 4242;
    private static volatile boolean active = false;

    /** Is tracker mode currently running? (used by the shell menu) */
    public static boolean running(Context ctx) { return active; }

    private LocationManager lm;
    private int interval = 30;
    private String lastNote = "waiting for the first fix…";

    @Override public IBinder onBind(Intent intent) { return null; }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        startForeground(NOTIFY_ID, notification("Tracker mode is on — waiting for a fix…"));
        if (!active) {
            active = true;
            SharedPreferences p = getSharedPreferences(MainActivity.PREFS, Context.MODE_PRIVATE);
            interval = Math.max(5, p.getInt("interval_s", 30));
            lm = (LocationManager) getSystemService(Context.LOCATION_SERVICE);
            try {
                if (lm != null) {
                    lm.requestLocationUpdates(LocationManager.GPS_PROVIDER, interval * 1000L, 0f, this);
                    if (lm.isProviderEnabled(LocationManager.NETWORK_PROVIDER)) {
                        lm.requestLocationUpdates(LocationManager.NETWORK_PROVIDER, interval * 1000L, 0f, this);
                    }
                }
            } catch (SecurityException e) {
                lastNote = "location permission missing";
                update("Tracker mode: " + lastNote);
            } catch (Exception e) {
                lastNote = "no location provider";
                update("Tracker mode: " + lastNote);
            }
        }
        return START_STICKY;             // Android restarts it if it is killed
    }

    @Override
    public void onDestroy() {
        active = false;
        try { if (lm != null) lm.removeUpdates(this); } catch (Exception ignored) { }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.N) stopForeground(STOP_FOREGROUND_REMOVE);
        else stopForeground(true);
        super.onDestroy();
    }

    @Override public void onLocationChanged(Location loc) { post(loc); }
    @Override public void onProviderEnabled(String provider) { }
    @Override public void onProviderDisabled(String provider) {
        lastNote = provider + " turned off";
        update("Tracker mode: " + lastNote);
    }
    @Override public void onStatusChanged(String provider, int status, Bundle extras) { }

    /* ------------------------------------------------------------- ingest -- */
    /** Sends one fix on a background thread — the network never blocks the UI. */
    private void post(final Location loc) {
        final SharedPreferences p = getSharedPreferences(MainActivity.PREFS, Context.MODE_PRIVATE);
        final String base = MainActivity.serverUrl(this);
        final String unit = p.getString("unit_id", "").trim();
        final String pass = p.getString("device_password", "");
        if (base.isEmpty() || unit.isEmpty()) {
            lastNote = "unit unique ID / server not configured";
            update("Tracker mode: " + lastNote);
            return;
        }
        new Thread(new Runnable() {
            @Override public void run() {
                HttpURLConnection conn = null;
                try {
                    JSONObject point = new JSONObject();
                    point.put("ts", iso(loc.getTime()));
                    point.put("lat", loc.getLatitude());
                    point.put("lon", loc.getLongitude());
                    if (loc.hasSpeed()) point.put("speed", Math.round(loc.getSpeed() * 3.6 * 10) / 10.0);
                    if (loc.hasBearing()) point.put("course", Math.round(loc.getBearing()));
                    if (loc.hasAltitude()) point.put("alt", Math.round(loc.getAltitude()));
                    if (loc.getAccuracy() > 0) point.put("accuracy", Math.round(loc.getAccuracy()));

                    JSONObject body = new JSONObject();
                    body.put("unique_id", unit);
                    body.put("password", pass);
                    JSONArray points = new JSONArray();
                    points.put(point);
                    body.put("points", points);

                    conn = (HttpURLConnection) new URL(base + "/api/ingest").openConnection();
                    conn.setRequestMethod("POST");
                    conn.setConnectTimeout(10000);
                    conn.setReadTimeout(15000);
                    conn.setDoOutput(true);
                    conn.setRequestProperty("Content-Type", "application/json");
                    OutputStream os = conn.getOutputStream();
                    os.write(body.toString().getBytes("UTF-8"));
                    os.close();
                    int code = conn.getResponseCode();
                    String text = read(code >= 400 ? conn.getErrorStream() : conn.getInputStream());
                    if (code == 200) {
                        JSONObject res = new JSONObject(text);
                        lastNote = "stored +" + res.optInt("stored", 0) + " at " + hhmm(loc.getTime());
                    } else {
                        lastNote = "server said " + code + " " + trim(text);
                    }
                } catch (Exception e) {
                    lastNote = "offline — retrying next fix";
                } finally {
                    if (conn != null) conn.disconnect();
                    update("Tracker mode — " + lastNote);
                }
            }
        }).start();
    }

    private static String read(java.io.InputStream in) throws Exception {
        if (in == null) return "";
        BufferedReader r = new BufferedReader(new InputStreamReader(in, "UTF-8"));
        StringBuilder sb = new StringBuilder();
        String line;
        while ((line = r.readLine()) != null) sb.append(line);
        r.close();
        return sb.toString();
    }
    private static String trim(String s) { return s.length() > 90 ? s.substring(0, 90) + "…" : s; }
    /* the platform parses ISO 8601 (strtotime) — send the fix time in local offset */
    private static String iso(long when) {
        SimpleDateFormat f = new SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ssZ", Locale.US);
        f.setTimeZone(TimeZone.getDefault());
        return f.format(new Date(when));
    }
    private static String hhmm(long when) {
        SimpleDateFormat f = new SimpleDateFormat("HH:mm:ss", Locale.US);
        return f.format(new Date(when));
    }

    /* ------------------------------------------------------- notification -- */
    private void update(String text) { notify(text); }
    private void notify(String text) {
        NotificationManager nm = (NotificationManager) getSystemService(Context.NOTIFICATION_SERVICE);
        if (nm != null) nm.notify(NOTIFY_ID, notification(text));
    }
    private Notification notification(String text) {
        NotificationManager nm = (NotificationManager) getSystemService(Context.NOTIFICATION_SERVICE);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O && nm != null) {
            NotificationChannel ch = new NotificationChannel(CHANNEL, "Tracker mode",
                NotificationManager.IMPORTANCE_LOW);
            ch.setDescription("Keeps this phone reporting its position");
            nm.createNotificationChannel(ch);
        }
        Intent open = new Intent(this, MainActivity.class);
        int flags = PendingIntent.FLAG_UPDATE_CURRENT;
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) flags |= PendingIntent.FLAG_IMMUTABLE;
        PendingIntent pi = PendingIntent.getActivity(this, 0, open, flags);
        Notification.Builder b = Build.VERSION.SDK_INT >= Build.VERSION_CODES.O
            ? new Notification.Builder(this, CHANNEL) : new Notification.Builder(this);
        b.setContentTitle(getString(R.string.app_name))
            .setContentText(text)
            .setSmallIcon(R.drawable.ic_launcher)
            .setContentIntent(pi)
            .setOngoing(true);
        return b.build();
    }
}
