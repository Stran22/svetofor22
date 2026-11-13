
package ru.svet22.app

import android.Manifest
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import android.content.Intent
import android.util.Log
import androidx.compose.runtime.mutableStateOf
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.core.content.ContextCompat
import androidx.compose.ui.Modifier
import com.google.firebase.messaging.FirebaseMessaging
import com.vk.id.VKID
import com.vk.id.auth.VKIDAuthCallback
import com.vk.id.VKIDAuthFail
import com.vk.id.auth.VKIDAuthParams
import com.vk.id.auth.AuthCodeData
import com.vk.id.AccessToken
import ru.svet22.app.auth.OAuthPkce
import ru.svet22.app.auth.VkLoginRepository
import ru.svet22.app.auth.ReviewAuthRepository
import androidx.lifecycle.lifecycleScope
import kotlinx.coroutines.launch
import ru.svet22.app.navigation.AppNavigation
import ru.svet22.app.push.FcmRegistrationManager

class MainActivity : ComponentActivity() {
    private val notificationTarget = mutableStateOf<Pair<Int, Int>?>(null)
    private val vkStatus = mutableStateOf("Проверяем авторизацию…")
    private val isSocialAuthenticated = mutableStateOf(false)
    private var pendingVkAttempt: OAuthPkce.Challenge? = null

    private val vkCallback = object : VKIDAuthCallback {
        override fun onAuth(accessToken: AccessToken) {
            pendingVkAttempt = null
            vkStatus.value = "Не удалось завершить безопасный вход. Попробуйте снова."
        }

        override fun onAuthCode(data: AuthCodeData, isCompletion: Boolean) {
            if (pendingVkAttempt == null || data.code.isBlank() || data.deviceId.isBlank()) {
                pendingVkAttempt = null
                vkStatus.value = "VK ID вернул неполные данные. Попробуйте снова."
                return
            }
            // Exchange credentials on the backend, never in the Android app.
            val attempt = pendingVkAttempt ?: return
            pendingVkAttempt = null
            vkStatus.value = "Проверяем вход на сервере…"
            lifecycleScope.launch {
                try {
                    val verified = VkLoginRepository.complete(
                        applicationContext, data.code, data.deviceId, attempt
                    )
                    if (verified) isSocialAuthenticated.value = true
                    vkStatus.value = if (verified) {
                        "Вход через VK ID выполнен. Теперь можно оставлять отзывы."
                    } else {
                        "Сервер не подтвердил вход через VK ID."
                    }
                } catch (e: Exception) {
                    Log.w("SVET22_VK", "VK ID server verification failed: ${e.javaClass.simpleName}")
                    vkStatus.value = "Не удалось подтвердить вход. Попробуйте ещё раз."
                }
            }
        }

        override fun onFail(fail: VKIDAuthFail) {
            pendingVkAttempt = null
            vkStatus.value = if (fail is VKIDAuthFail.Canceled) {
                "Вход отменён."
            } else {
                "Не удалось выполнить вход через VK ID."
            }
        }
    }

    private fun logoutVkAccount() {
        vkStatus.value = "Выходим из аккаунта…"
        lifecycleScope.launch {
            try {
                ReviewAuthRepository.logout(applicationContext)
                isSocialAuthenticated.value = false
                vkStatus.value = "Вы вышли из аккаунта. Для отзывов войдите через VK ID."
            } catch (e: Exception) {
                Log.w("SVET22_VK", "Logout failed: ${e.javaClass.simpleName}")
                vkStatus.value = "Не удалось выйти. Проверьте подключение к интернету."
            }
        }
    }

    private fun startVkLogin() {
        if (pendingVkAttempt != null) return
        val attempt = OAuthPkce.create()
        pendingVkAttempt = attempt
        vkStatus.value = "Открываем VK ID…"
        try {
            val params = VKIDAuthParams.Builder().apply {
                codeChallenge = attempt.challenge
                state = attempt.state
            }.build()
            VKID.instance.authorize(this, vkCallback, params)
        } catch (e: Exception) {
            pendingVkAttempt = null
            Log.e("SVET22_VK", "Unable to start VK ID authorization", e)
            vkStatus.value = "Не удалось открыть VK ID."
        }
    }

    private fun handleNotificationIntent(intent: Intent?) {
        if (intent == null) return
        val extras = intent.extras
        // Firebase notification payloads can deliver data extras as Strings,
        // while our own notification PendingIntent uses Int extras.
        fun positiveId(key: String): Int? {
            val value = extras?.get(key) ?: return null
            return when (value) {
                is Int -> value
                is Long -> value.toInt()
                is String -> value.trim().toIntOrNull()
                else -> null
            }?.takeIf { it > 0 }
        }
        val productId = positiveId("product_id")
        val branchId = positiveId("branch_id")
        Log.i("SVET22_PUSH", "Notification opened: productIdPresent=${productId != null}, branchIdPresent=${branchId != null}, keys=${extras?.keySet()?.joinToString() ?: "none"}")
        if (productId != null && branchId != null) {
            notificationTarget.value = productId to branchId
            intent.removeExtra("product_id")
            intent.removeExtra("branch_id")
        }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        handleNotificationIntent(intent)
    }


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
        handleNotificationIntent(intent)
        lifecycleScope.launch {
            try {
                isSocialAuthenticated.value = ReviewAuthRepository.isAuthenticated(applicationContext)
                vkStatus.value = if (isSocialAuthenticated.value) "Вы вошли через VK ID." else "Для отзывов войдите через VK ID."
            } catch (_: Exception) {
                vkStatus.value = "Не удалось проверить авторизацию."
            }
        }
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
                    AppNavigation(
                        notificationTarget = notificationTarget.value,
                        onNotificationHandled = { notificationTarget.value = null },
                        onVkLoginClick = { startVkLogin() },
                        onLogoutClick = { logoutVkAccount() },
                        vkLoginStatus = vkStatus.value,
                        isSocialAuthenticated = isSocialAuthenticated.value
                    )
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
