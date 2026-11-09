package ru.svet22.app.push

import android.content.Context
import com.google.firebase.messaging.FirebaseMessaging
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import java.util.UUID
import java.util.concurrent.Executors

/**
 * Registers this installation and its FCM token with the device-authenticated API.
 * Network operations never run on the main thread.
 */
object FcmRegistrationManager {
    private const val BASE_URL = "https://baza.altaj-otdyh.ru/api/v1"
    private const val PREFS = "svet22_device_auth"
    private const val DEVICE_ID = "device_id"
    private const val ACCESS_TOKEN = "access_token"
    private val executor = Executors.newSingleThreadExecutor()

    fun register(context: Context) {
        val appContext = context.applicationContext
        FirebaseMessaging.getInstance().token.addOnSuccessListener { token ->
            registerToken(appContext, token)
        }
    }

    fun registerToken(context: Context, fcmToken: String) {
        if (fcmToken.isBlank()) return
        val appContext = context.applicationContext
        executor.execute {
            try {
                val prefs = appContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
                val deviceId = prefs.getString(DEVICE_ID, null)
                    ?: UUID.randomUUID().toString().also {
                        prefs.edit().putString(DEVICE_ID, it).apply()
                    }

                fun authenticate(): String? {
                    val (status, body) = post(
                        "/auth/device",
                        JSONObject().put("device_id", deviceId),
                        null
                    )
                    if (status !in 200..299) return null
                    val json = JSONObject(body)
                    val token = json.optString("access_token").ifBlank {
                        json.optString("token").ifBlank {
                            json.optJSONObject("data")?.optString("access_token").orEmpty()
                        }
                    }
                    if (token.isBlank()) return null
                    prefs.edit().putString(ACCESS_TOKEN, token).apply()
                    return token
                }

                var accessToken = prefs.getString(ACCESS_TOKEN, null)
                    ?.takeIf { it.isNotBlank() } ?: authenticate() ?: return@execute

                val payload = JSONObject()
                    .put("fcm_token", fcmToken)
                    .put("platform", "android")

                var (status, _) = post("/push/register", payload, accessToken)
                if (status == HttpURLConnection.HTTP_UNAUTHORIZED) {
                    prefs.edit().remove(ACCESS_TOKEN).apply()
                    accessToken = authenticate() ?: return@execute
                    status = post("/push/register", payload, accessToken).first
                }
                // A later startup or FCM token refresh retries transient failures.
            } catch (_: Exception) {
                // Never log device credentials or FCM tokens.
            }
        }
    }

    private fun post(path: String, payload: JSONObject, bearer: String?): Pair<Int, String> {
        val connection = (URL(BASE_URL + path).openConnection() as HttpURLConnection)
        try {
            connection.requestMethod = "POST"
            connection.connectTimeout = 10000
            connection.readTimeout = 10000
            connection.doOutput = true
            connection.setRequestProperty("Content-Type", "application/json; charset=utf-8")
            connection.setRequestProperty("Accept", "application/json")
            if (bearer != null) connection.setRequestProperty("Authorization", "Bearer $bearer")
            connection.outputStream.use { it.write(payload.toString().toByteArray(Charsets.UTF_8)) }
            val status = connection.responseCode
            val stream = if (status in 200..299) connection.inputStream else connection.errorStream
            val body = stream?.bufferedReader(Charsets.UTF_8)?.use { it.readText() }.orEmpty()
            return status to body
        } finally {
            connection.disconnect()
        }
    }
}
