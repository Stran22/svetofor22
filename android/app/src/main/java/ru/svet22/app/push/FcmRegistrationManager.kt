package ru.svet22.app.push

import android.content.Context
import android.util.Log
import com.google.firebase.messaging.FirebaseMessaging
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONObject
import java.io.IOException
import java.util.UUID
import java.util.concurrent.Executors
import java.util.concurrent.TimeUnit

/**
 * Registers the Android installation and its Firebase token with the legacy Svet22 API.
 * Does not print credentials, bearer tokens, or FCM tokens to Logcat.
 */
object FcmRegistrationManager {
    private const val TAG = "SVET22_FCM"
    private const val BASE_URL = "https://baza.altaj-otdyh.ru"
    private const val PREFS = "svet22_device_auth"
    private const val KEY_DEVICE_ID = "device_id"
    private const val KEY_ACCESS_TOKEN = "access_token"
    private val executor = Executors.newSingleThreadExecutor()
    private val client = OkHttpClient.Builder()
        .connectTimeout(10, TimeUnit.SECONDS)
        .readTimeout(15, TimeUnit.SECONDS)
        .build()
    private val jsonType = "application/json; charset=utf-8".toMediaType()

    /** Call at application startup; safe to call repeatedly. */
    fun registerCurrentToken(context: Context) {
        val appContext = context.applicationContext
        FirebaseMessaging.getInstance().token.addOnCompleteListener { task ->
            if (!task.isSuccessful || task.result.isNullOrBlank()) {
                Log.w(TAG, "FCM token is not available", task.exception)
                return@addOnCompleteListener
            }
            registerToken(appContext, task.result!!)
        }
    }

    /** Call from FirebaseMessagingService.onNewToken(token). */
    fun registerToken(context: Context, fcmToken: String) {
        if (fcmToken.isBlank()) return
        val appContext = context.applicationContext
        executor.execute {
            try {
                val prefs = appContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
                var deviceId = prefs.getString(KEY_DEVICE_ID, null)
                if (deviceId.isNullOrBlank()) {
                    deviceId = UUID.randomUUID().toString()
                    if (!prefs.edit().putString(KEY_DEVICE_ID, deviceId).commit()) {
                        throw IOException("Cannot persist device ID")
                    }
                }
                var bearer = prefs.getString(KEY_ACCESS_TOKEN, null)
                if (bearer.isNullOrBlank()) {
                    bearer = authenticateDevice(deviceId)
                    if (!prefs.edit().putString(KEY_ACCESS_TOKEN, bearer).commit()) {
                        throw IOException("Cannot persist authorization")
                    }
                }
                var response = postPushToken(fcmToken, bearer)
                if (response == 401) {
                    // The server may have revoked an old bearer token.
                    bearer = authenticateDevice(deviceId)
                    if (!prefs.edit().putString(KEY_ACCESS_TOKEN, bearer).commit()) {
                        throw IOException("Cannot persist refreshed authorization")
                    }
                    response = postPushToken(fcmToken, bearer)
                }
                if (response in 200..299) {
                    Log.i(TAG, "FCM token registered on server")
                } else {
                    Log.w(TAG, "FCM registration failed: HTTP $response")
                }
            } catch (e: Exception) {
                Log.e(TAG, "FCM registration failed", e)
            }
        }
    }

    private fun authenticateDevice(deviceId: String): String {
        val body = JSONObject().put("device_id", deviceId).toString()
        val request = Request.Builder()
            .url("$BASE_URL/api/v1/auth/device")
            .post(body.toRequestBody(jsonType))
            .build()
        client.newCall(request).execute().use { response ->
            if (!response.isSuccessful) {
                throw IOException("Device authorization failed: HTTP ${response.code}")
            }
            val json = JSONObject(response.body?.string() ?: "{}")
            // Supports both a direct API response and a {success, data} envelope.
            val data = json.optJSONObject("data") ?: json
            return data.optString("access_token").takeIf { it.isNotBlank() }
                ?: throw IOException("Missing access_token in device authorization response")
        }
    }

    private fun postPushToken(fcmToken: String, bearer: String): Int {
        val body = JSONObject()
            .put("fcm_token", fcmToken)
            .put("platform", "android")
            .toString()
        val request = Request.Builder()
            .url("$BASE_URL/api/v1/push/register")
            .header("Authorization", "Bearer $bearer")
            .post(body.toRequestBody(jsonType))
            .build()
        client.newCall(request).execute().use { response ->
            return response.code
        }
    }
}
