package com.kivutracker.app;

import android.Manifest;
import android.app.Activity;
import android.app.AlertDialog;
import android.app.DownloadManager;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.text.InputType;
import android.view.Menu;
import android.view.MenuItem;
import android.view.View;
import android.webkit.CookieManager;
import android.webkit.DownloadListener;
import android.webkit.WebChromeClient;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.TextView;
import android.widget.Toast;

/**
 * The KIVU Tracker Android client.
 *
 * Console mode: a WebView that loads &lt;server&gt;/app — the same console the
 * desktop browsers use, so every feature stays in one place.
 * Tracker mode: {@link TrackerService} turns this phone into a unit that posts
 * its own GPS to the platform's /api/ingest endpoint.
 */
public class MainActivity extends Activity {

    public static final String PREFS = "kivu";
    private WebView web;
    private TextView status;

    private SharedPreferences prefs() { return getSharedPreferences(PREFS, Context.MODE_PRIVATE); }

    /** Server address without a trailing slash, or "" when not configured yet. */
    public static String serverUrl(Context ctx) {
        String s = ctx.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getString("server_url", "");
        if (s == null) s = "";
        s = s.trim();
        while (s.endsWith("/")) s = s.substring(0, s.length() - 1);
        return s;
    }

    @Override
    protected void onCreate(Bundle state) {
        super.onCreate(state);
        setContentView(R.layout.activity_main);
        web = findViewById(R.id.web);
        status = findViewById(R.id.status);

        WebSettings s = web.getSettings();
        s.setJavaScriptEnabled(true);
        s.setDomStorageEnabled(true);
        s.setDatabaseEnabled(true);
        s.setBuiltInZoomControls(false);
        s.setLoadWithOverviewMode(true);
        s.setUseWideViewPort(true);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP) {
            s.setMixedContentMode(WebSettings.MIXED_CONTENT_ALWAYS_ALLOW);
        }
        CookieManager.getInstance().setAcceptCookie(true);
        web.setWebViewClient(new WebViewClient());
        web.setWebChromeClient(new WebChromeClient());
        /* the console offers the APK and CSV/report downloads — hand them to Android */
        web.setDownloadListener(new DownloadListener() {
            @Override public void onDownloadStart(String url, String agent, String disposition,
                                                  String mime, long size) {
                try {
                    DownloadManager.Request r = new DownloadManager.Request(Uri.parse(url));
                    r.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);
                    DownloadManager dm = (DownloadManager) getSystemService(Context.DOWNLOAD_SERVICE);
                    if (dm != null) dm.enqueue(r);
                    Toast.makeText(MainActivity.this, "Downloading…", Toast.LENGTH_SHORT).show();
                } catch (Exception e) {
                    startActivity(new Intent(Intent.ACTION_VIEW, Uri.parse(url)));
                }
            }
        });

        if (serverUrl(this).isEmpty()) askServer(true);
        else loadConsole();
    }

    private void loadConsole() {
        String base = serverUrl(this);
        if (base.isEmpty()) { askServer(true); return; }
        web.loadUrl(base + "/app");
    }

    /** Sensible default for the very first run (build-time value). */
    private String serverHint() {
        try { return getString(R.string.server_default); }
        catch (Exception e) { return "http://192.168.1.10:8099"; }
    }

    /** First-run / settings dialog: server address + optional tracker details. */
    private void askServer(final boolean firstRun) {
        final SharedPreferences p = prefs();
        LinearLayout box = new LinearLayout(this);
        box.setOrientation(LinearLayout.VERTICAL);
        int pad = (int) (getResources().getDisplayMetrics().density * 16);
        box.setPadding(pad, pad, pad, pad);

        final EditText server = new EditText(this);
        server.setHint(getString(R.string.settings_server));
        server.setInputType(InputType.TYPE_TEXT_VARIATION_URI);
        String saved = p.getString("server_url", "");
        server.setText(saved == null || saved.isEmpty() ? serverHint() : saved);
        box.addView(server);

        final EditText unit = new EditText(this);
        unit.setHint(getString(R.string.settings_unit));
        unit.setText(p.getString("unit_id", ""));
        box.addView(unit);

        final EditText pass = new EditText(this);
        pass.setHint(getString(R.string.settings_password));
        pass.setText(p.getString("device_password", ""));
        box.addView(pass);

        final EditText every = new EditText(this);
        every.setHint(getString(R.string.settings_interval));
        every.setInputType(InputType.TYPE_CLASS_NUMBER);
        every.setText(String.valueOf(p.getInt("interval_s", 30)));
        box.addView(every);

        new AlertDialog.Builder(this)
            .setTitle(R.string.settings_title)
            .setView(box)
            .setCancelable(!firstRun)
            .setPositiveButton(R.string.save, (d, w) -> {
                String url = server.getText().toString().trim();
                while (url.endsWith("/")) url = url.substring(0, url.length() - 1);
                if (!url.startsWith("http://") && !url.startsWith("https://")) {
                    Toast.makeText(this, R.string.bad_server, Toast.LENGTH_LONG).show();
                    return;
                }
                int secs;
                try { secs = Integer.parseInt(every.getText().toString().trim()); }
                catch (Exception e) { secs = 30; }
                p.edit()
                    .putString("server_url", url)
                    .putString("unit_id", unit.getText().toString().trim())
                    .putString("device_password", pass.getText().toString().trim())
                    .putInt("interval_s", Math.max(5, Math.min(3600, secs)))
                    .apply();
                loadConsole();
            })
            .setNegativeButton(firstRun ? "Exit" : getString(R.string.cancel),
                (d, w) -> { if (firstRun) finish(); })
            .show();
    }

    @Override
    public boolean onCreateOptionsMenu(Menu menu) {
        getMenuInflater().inflate(R.menu.main, menu);
        menu.findItem(R.id.action_tracker).setTitle(
            TrackerService.running(this) ? R.string.menu_tracker_stop : R.string.menu_tracker_start);
        return true;
    }

    @Override
    public boolean onMenuItemSelected(int featureId, MenuItem item) {
        int id = item.getItemId();
        if (id == R.id.action_reload) { loadConsole(); return true; }
        if (id == R.id.action_settings) { askServer(false); return true; }
        if (id == R.id.action_tracker) { toggleTracker(); return true; }
        if (id == R.id.action_about) { showAbout(); return true; }
        return super.onMenuItemSelected(featureId, item);
    }

    /** Starts/stops tracker mode, asking for the location permission when needed. */
    private void toggleTracker() {
        if (TrackerService.running(this)) {
            stopService(new Intent(this, TrackerService.class));
            setTrackerStatus("");
            invalidateOptionsMenu();
            Toast.makeText(this, "Tracker mode stopped", Toast.LENGTH_SHORT).show();
            return;
        }
        if (prefs().getString("unit_id", "").trim().isEmpty()) {
            Toast.makeText(this, "Set the unit unique ID in Server settings first", Toast.LENGTH_LONG).show();
            askServer(false);
            return;
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M && checkSelfPermission(
                Manifest.permission.ACCESS_FINE_LOCATION) != PackageManager.PERMISSION_GRANTED) {
            requestPermissions(new String[]{Manifest.permission.ACCESS_FINE_LOCATION}, 42);
            return;
        }
        startTracker();
    }

    private void startTracker() {
        Intent svc = new Intent(this, TrackerService.class);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) startForegroundService(svc);
        else startService(svc);
        setTrackerStatus(getString(R.string.tracker_notice));
        invalidateOptionsMenu();
    }

    @Override
    public void onRequestPermissionsResult(int code, String[] perms, int[] results) {
        super.onRequestPermissionsResult(code, perms, results);
        if (code == 42) {
            if (results.length > 0 && results[0] == PackageManager.PERMISSION_GRANTED) startTracker();
            else Toast.makeText(this, R.string.location_needed, Toast.LENGTH_LONG).show();
        }
    }

    private void showAbout() {
        String base = serverUrl(this);
        String ingest = base.isEmpty() ? "/api/ingest" : base + "/api/ingest";
        new AlertDialog.Builder(this)
            .setTitle(R.string.menu_about)
            .setMessage("KIVU Tracker Android client\n\n"
                + "Server: " + (base.isEmpty() ? "(not set)" : base) + "\n"
                + "Console: " + (base.isEmpty() ? "-" : base + "/app") + "\n\n"
                + "Tracker mode posts this phone's position to " + ingest + " with the unit unique ID\n"
                + "and device password you entered. The unit must exist under Administration -> Units\n"
                + "(hardware type 'phone' - see the supported-device list in the super console).")
            .setPositiveButton("OK", null)
            .show();
    }

    @Override
    public void onBackPressed() {
        if (web != null && web.canGoBack()) web.goBack();
        else super.onBackPressed();
    }

    /** Used by the tracker service to report its state in the shell. */
    public void setTrackerStatus(final String text) {
        runOnUiThread(() -> {
            if (status == null) return;
            status.setText(text == null ? "" : text);
            status.setVisibility(text == null || text.isEmpty() ? View.GONE : View.VISIBLE);
        });
    }
}
