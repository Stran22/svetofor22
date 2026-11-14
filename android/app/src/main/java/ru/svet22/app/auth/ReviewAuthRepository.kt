package ru.svet22.app.auth

import android.content.Context
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONObject
import ru.svet22.app.push.FcmRegistrationManager
import java.io.IOException

/** Uses the same device bearer as VK ID and FCM; never stores a separate review account. */
object ReviewAuthRepository {
    private val http = OkHttpClient()
    private val jsonType = "application/json; charset=utf-8".toMediaType()
    private const val base = "https://baza.altaj-otdyh.ru/api/v1"

    suspend fun isAuthenticated(context: Context): Boolean = withContext(Dispatchers.IO) {
        val token = FcmRegistrationManager.getAccessToken(context)
        val request = Request.Builder().url("$base/me").header("Authorization", "Bearer $token").get().build()
        http.newCall(request).execute().use { response ->
            if (!response.isSuccessful) return@withContext false
            val json = JSONObject(response.body?.string() ?: "{}")
            json.optBoolean("success") && json.optJSONObject("data")?.optInt("is_guest", 1) == 0
        }
    }

    /** Revoke the active account bearer before switching this installation to guest mode. */
    suspend fun logout(context: Context): Unit = withContext(Dispatchers.IO) {
        val token = FcmRegistrationManager.getAccessToken(context)
        FcmRegistrationManager.unregisterCurrentToken(context, token)
        val request = Request.Builder().url("$base/auth/logout")
            .header("Authorization", "Bearer $token")
            .post("{}".toRequestBody(jsonType))
            .build()
        http.newCall(request).execute().use { response ->
            if (!response.isSuccessful) {
                throw IOException("Не удалось выйти из аккаунта (HTTP ${response.code}).")
            }
            val result = JSONObject(response.body?.string() ?: "{}")
            if (!result.optBoolean("success")) {
                throw IOException("Сервер не подтвердил выход.")
            }
        }
        FcmRegistrationManager.resetToGuest(context)
    }

    suspend fun submit(context: Context, productId: Int, rating: Int, text: String): String =
        withContext(Dispatchers.IO) {
            require(rating in 1..5)
            require(text.length <= 500)
            val token = FcmRegistrationManager.getAccessToken(context)
            val body = JSONObject().put("rating", rating).put("review_text", text.trim()).toString()
            val request = Request.Builder().url("$base/products/$productId/reviews")
                .header("Authorization", "Bearer $token")
                .post(body.toRequestBody(jsonType)).build()
            http.newCall(request).execute().use { response ->
                val json = JSONObject(response.body?.string() ?: "{}")
                if (!response.isSuccessful || !json.optBoolean("success")) {
                    val code = json.optJSONObject("error")?.optString("code").orEmpty()
                    throw IOException(when (code) {
                        "REVIEW_EXISTS" -> "Вы уже оставили отзыв на этот товар."
                        "SOCIAL_LOGIN_REQUIRED", "UNAUTHENTICATED" -> "Войдите через VK ID и попробуйте снова."
                        else -> "Не удалось отправить отзыв (HTTP ${response.code})."
                    })
                }
                json.optJSONObject("data")?.optString("status") ?: "pending"
            }
        }
}
