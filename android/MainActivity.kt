
package ru.svet22.app

import android.Manifest
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.core.content.ContextCompat
import androidx.compose.ui.Modifier
import com.google.firebase.messaging.FirebaseMessaging
import ru.svet22.app.navigation.AppNavigation
import ru.svet22.app.push.FcmRegistrationManager

class MainActivity : ComponentActivity() {

    // Системное окно запроса разрешения на уведомления
    private val notificationPermissionLauncher =
        registerForActivityResult(
            ActivityResultContracts.RequestPermission()
        ) { isGranted ->
            // Android сохраняет решение пользователя.
            // Дополнительных действий здесь пока не требуется.
        }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        // ===== НАЧАЛО БЛОКА: Регистрация Firebase на сервере =====

        FcmRegistrationManager.registerCurrentToken(this)

// ===== КОНЕЦ БЛОКА: Регистрация Firebase на сервере =====

        FirebaseMessaging.getInstance().token
            .addOnCompleteListener { task ->
                if (task.isSuccessful && !task.result.isNullOrBlank()) {
                    android.util.Log.i("SVET22_FCM", "FCM token received successfully")
                } else {
                    android.util.Log.e("SVET22_FCM", "FCM token request failed", task.exception)
                }
            }

        // ===== НАЧАЛО БЛОКА: Основная тема приложения =====
        setContent {
            MaterialTheme {
                Surface(
                    modifier = Modifier.fillMaxSize()
                ) {
                    // ===== НАЧАЛО БЛОКА: Запуск навигации =====
                    AppNavigation()
                    // ===== КОНЕЦ БЛОКА: Запуск навигации =====
                }
            }
        }
        // ===== КОНЕЦ БЛОКА: Основная тема приложения =====

        requestNotificationPermission()
    }

    // Запрашиваем разрешение только на Android 13+
    private fun requestNotificationPermission() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {

            val permission = Manifest.permission.POST_NOTIFICATIONS

            if (ContextCompat.checkSelfPermission(
                    this,
                    permission
                ) != PackageManager.PERMISSION_GRANTED
            ) {
                notificationPermissionLauncher.launch(permission)
            }
        }
    }
}
