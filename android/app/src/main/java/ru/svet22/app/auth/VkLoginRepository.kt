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
import java.util.concurrent.TimeUnit

/** The VK authorization code is exchanged ONLY by our PHP backend. */
object VkLoginRepository {
    private val http = OkHttpClient.Builder()
        .connectTimeout(10, TimeUnit.SECONDS)
        .readTimeout(20, TimeUnit.SECONDS)
        .build()
    private val jsonType = "application/json; charset=utf-8".toMediaType()

    suspend fun complete(
        context: Context,
        code: String,
        vkDeviceId: String,
        attempt: OAuthPkce.Challenge
    ): Boolean = withContext(Dispatchers.IO) {
        val bearer = FcmRegistrationManager.getAccessToken(context)
        val body = JSONObject()
            .put("code", code)
            .put("device_id", vkDeviceId)
            .put("code_verifier", attempt.verifier)
            .put("state", attempt.state)
            .toString()
        val request = Request.Builder()
            .url("https://baza.altaj-otdyh.ru/api/v1/auth/vk")
            .header("Authorization", "Bearer $bearer")
            .post(body.toRequestBody(jsonType))
            .build()
        http.newCall(request).execute().use { response ->
            if (!response.isSuccessful) {
                throw IOException("VK ID server verification failed: HTTP ${response.code}")
            }
            val json = JSONObject(response.body?.string() ?: "{}")
            val data = json.optJSONObject("data")
            val verified = json.optBoolean("success") && data != null &&
                !data.optBoolean("is_guest", true) &&
                data.optString("provider") == "vk"
            if (verified) {
                val accountToken = data!!.optString("access_token")
                if (accountToken.isBlank()) {
                    throw IOException("VK verification response is missing account bearer")
                }
                FcmRegistrationManager.useVerifiedAccountToken(context, accountToken)
            }
            verified
        }
    }
}
