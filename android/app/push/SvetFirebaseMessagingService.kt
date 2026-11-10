package ru.svet22.app.push

import android.Manifest
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.core.content.ContextCompat
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import ru.svet22.app.MainActivity

/**
 * Receives FCM messages. Server-side token registration is implemented separately:
 * do not send tokens to unauthenticated endpoints or log them.
 */
class SvetFirebaseMessagingService : FirebaseMessagingService() {
    override fun onNewToken(token: String) {
        super.onNewToken(token)
        // Register the refreshed FCM token using the existing authenticated device API.
        // FcmRegistrationManager handles background I/O and reauthenticates on HTTP 401.
        FcmRegistrationManager.registerToken(applicationContext, token)
    }

    override fun onMessageReceived(message: RemoteMessage) {
        super.onMessageReceived(message)
        val title = message.notification?.title
            ?: message.data["title"]
            ?: "СВЕТОФОР"
        val body = message.notification?.body
            ?: message.data["body"]
            ?: return

        val manager = getSystemService(NotificationManager::class.java)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            manager.createNotificationChannel(
                NotificationChannel(
                    CHANNEL_ID,
                    "Появление товаров",
                    NotificationManager.IMPORTANCE_DEFAULT
                )
            )
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU &&
            ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS) !=
            PackageManager.PERMISSION_GRANTED
        ) return

        val intent = Intent(this, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP
            message.data["product_id"]?.toIntOrNull()?.let { putExtra("product_id", it) }
            message.data["branch_id"]?.toIntOrNull()?.let { putExtra("branch_id", it) }
        }
        val pendingIntent = PendingIntent.getActivity(
            this, 0, intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
        val notification = NotificationCompat.Builder(this, CHANNEL_ID)
            .setSmallIcon(android.R.drawable.ic_dialog_info)
            .setContentTitle(title)
            .setContentText(body)
            .setAutoCancel(true)
            .setContentIntent(pendingIntent)
            .build()
        manager.notify(message.messageId?.hashCode() ?: System.currentTimeMillis().toInt(), notification)
    }

    companion object {
        private const val CHANNEL_ID = "restock_alerts"
    }
}
