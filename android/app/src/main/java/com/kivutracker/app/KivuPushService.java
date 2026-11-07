package com.kivutracker.app;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.content.Context;
import android.content.Intent;
import android.os.Build;

import com.google.firebase.messaging.FirebaseMessagingService;
import com.google.firebase.messaging.RemoteMessage;

/**
 * Push alerts for KIVU Tracker (FCM).
 *
 * <p>Messages arrive immediately even when the app is closed or swiped away —
 * Play services wakes the app just long enough to post the banner. Tapping a
 * banner opens {@link MainActivity}. Repeats of the same alert share one tag,
 * so the banner re-alerts (sound/vibrate) instead of stacking a queue.
 */
public class KivuPushService extends FirebaseMessagingService {

    public static final String CHANNEL_ID = "kivu_alerts";

    /** Fresh registration token (also issued once per app install). */
    @Override
    public void onNewToken(String token) {
        if (token == null || token.isEmpty()) return;
        getSharedPreferences(MainActivity.PREFS, MODE_PRIVATE)
            .edit().putString("fcm_token", token).apply();
        DeviceReporter.registerAsync(this, token);
    }

    /** Called for foreground messages; background banners are shown by Play services itself. */
    @Override
    public void onMessageReceived(RemoteMessage msg) {
        String title = null, body = null, tag = null;
        if (msg.getNotification() != null) {
            title = msg.getNotification().getTitle();
            body = msg.getNotification().getBody();
            tag = msg.getNotification().getTag();
        }
        if (title == null || title.isEmpty()) title = msg.getData().get("title");
        if (body == null || body.isEmpty()) body = msg.getData().get("body");
        if (tag == null || tag.isEmpty()) tag = msg.getData().get("tag");
        if (title == null || title.isEmpty()) title = getString(R.string.app_name);
        if (body == null || body.isEmpty()) body = "New alert";
        notifyUser(tag, title, body);
    }

    private void notifyUser(String tag, String title, String body) {
        NotificationManager nm = (NotificationManager) getSystemService(Context.NOTIFICATION_SERVICE);
        if (nm == null) return;

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O && nm.getNotificationChannel(CHANNEL_ID) == null) {
            NotificationChannel ch = new NotificationChannel(CHANNEL_ID,
                    "KIVU alerts", NotificationManager.IMPORTANCE_HIGH);
            ch.enableVibration(true);
            ch.setVibrationPattern(new long[]{0, 200, 100, 200});
            ch.setSound(android.provider.Settings.System.DEFAULT_NOTIFICATION_URI, null);
            ch.setDescription("Immediate fleet alerts from KIVU Tracker");
            nm.createNotificationChannel(ch);
        }

        Intent open = new Intent(this, MainActivity.class);
        open.setFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TOP);
        int flags = PendingIntent.FLAG_UPDATE_CURRENT;
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) flags |= PendingIntent.FLAG_IMMUTABLE;
        PendingIntent pi = PendingIntent.getActivity(this, 0, open, flags);

        Notification.Builder b;
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            b = new Notification.Builder(this, CHANNEL_ID);
        } else {
            b = new Notification.Builder(this)
                    .setPriority(Notification.PRIORITY_HIGH)
                    .setDefaults(Notification.DEFAULT_SOUND | Notification.DEFAULT_VIBRATE);
        }
        Notification n = b
                .setSmallIcon(R.drawable.ic_launcher)
                .setContentTitle(title)
                .setContentText(body)
                .setStyle(new Notification.BigTextStyle().bigText(body))
                .setAutoCancel(true)
                .setContentIntent(pi)
                .build();

        /* same tag -> same notification id: a repeat REPLACES and re-sounds */
        String nTag = (tag == null || tag.isEmpty()) ? "kivu-alert" : tag;
        nm.notify(nTag, nTag.hashCode(), n);
    }
}
