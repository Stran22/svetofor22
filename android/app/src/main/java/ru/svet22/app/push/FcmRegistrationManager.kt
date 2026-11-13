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

    // Shared device authorization for FCM and product subscriptions.
    // Call from a background thread (for example, Dispatchers.IO).
    @Synchronized
    fun getAccessToken(context: Context): String {
        val prefs = context.applicationContext
            .getSharedPreferences(PREFS, Context.MODE_PRIVATE)

        val savedToken = prefs.getString(KEY_ACCESS_TOKEN, null)
        if (!savedToken.isNullOrBlank()) return savedToken

        var deviceId = prefs.getString(KEY_DEVICE_ID, null)
        if (deviceId.isNullOrBlank()) {
            deviceId = UUID.randomUUID().toString()
            if (!prefs.edit().putString(KEY_DEVICE_ID, deviceId).commit()) {
                throw IOException("Cannot persist device ID")
            }
        }

        val newToken = authenticateDevice(deviceId)
        if (!prefs.edit().putString(KEY_ACCESS_TOKEN, newToken).commit()) {
            throw IOException("Cannot persist authorization")
        }
        return newToken
    }

    /** Save the bearer issued for the verified VK account and refresh push ownership. */
    @Synchronized
    fun useVerifiedAccountToken(context: Context, token: String) {
        if (token.isBlank()) throw IOException("Empty verified account token")
        val prefs = context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        if (!prefs.edit().putString(KEY_ACCESS_TOKEN, token).commit()) {
            throw IOException("Cannot persist verified account authorization")
        }
        registerCurrentToken(context)
    }

    /** Remove this device's FCM ownership before revoking the account bearer. */
    @Synchronized
    fun unregisterCurrentToken(context: Context, bearer: String) {
        val fcmToken = com.google.android.gms.tasks.Tasks.await(
            FirebaseMessaging.getInstance().token, 15, TimeUnit.SECONDS
        )
        if (fcmToken.isNullOrBlank()) throw IOException("FCM token unavailable")
        val body = JSONObject()
            .put("fcm_token", fcmToken)
            .put("platform", "android")
            .toString()
        val request = Request.Builder()
            .url("$BASE_URL/api/v1/push/unregister")
            .header("Authorization", "Bearer $bearer")
            .post(body.toRequestBody(jsonType))
            .build()
        client.newCall(request).execute().use { response ->
            if (!response.isSuccessful) {
                throw IOException("FCM unregister failed: HTTP ${response.code}")
            }
            val result = JSONObject(response.body?.string() ?: "{}")
            if (!result.optBoolean("success")) {
                throw IOException("FCM unregister not confirmed")
            }
        }
    }

    /**
     * Called after server logout. A new installation identity prevents reusing the
     * old social account when the app starts in guest mode.
     */
    @Synchronized
    fun resetToGuest(context: Context) {
        val prefs = context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        if (!prefs.edit()
                .putString(KEY_DEVICE_ID, UUID.randomUUID().toString())
                .remove(KEY_ACCESS_TOKEN)
                .commit()) {
            throw IOException("Cannot reset device authorization")
        }
        registerCurrentToken(context)
    }

    @Synchronized
    fun refreshAccessToken(context: Context): String {
        val prefs = context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val deviceId = prefs.getString(KEY_DEVICE_ID, null)
            ?: throw IOException("Device ID not initialized")
        val freshToken = authenticateDevice(deviceId)
        if (!prefs.edit().putString(KEY_ACCESS_TOKEN, freshToken).commit()) {
            throw IOException("Cannot persist refreshed authorization")
        }
        return freshToken
    }

    private fun authenticateDevice(deviceId: String): String {
        // Older API uses /auth/device with device_id and access_token.
        // Try the current guest endpoint first to preserve existing installations.
        val variants = listOf(
            "/api/v1/auth/guest" to JSONObject().put("device_uuid", deviceId).toString(),
            "/api/v1/auth/device" to JSONObject().put("device_id", deviceId).toString()
        )
        for ((index, variant) in variants.withIndex()) {
            val (endpoint, body) = variant
            val request = Request.Builder()
                .url("$BASE_URL$endpoint")
                .post(body.toRequestBody(jsonType))
                .build()
            client.newCall(request).execute().use { response ->
                if (response.code == 404 && index == 0) {
                    Log.w(TAG, "Guest auth route unavailable; trying device auth")
                    return@use
                }
                if (!response.isSuccessful) {
                    throw IOException("Device authorization failed: HTTP ${response.code}")
                }
                val json = JSONObject(response.body?.string() ?: "{}")
                val data = json.optJSONObject("data") ?: json
                return data.optString("token").takeIf { it.isNotBlank() }
                    ?: data.optString("access_token").takeIf { it.isNotBlank() }
                    ?: throw IOException("Missing access token in device authorization response")
            }
        }
        throw IOException("Device authorization endpoint not found")
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
