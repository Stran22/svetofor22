package ru.svet22.app.data.repository

import android.content.Context
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONObject
import ru.svet22.app.push.FcmRegistrationManager

/**
 * Best-effort analytics: a network failure must never interrupt catalog search.
 * Only finalized (debounced) queries are sent, without device identifiers.
 */
object SearchAnalyticsRepository {
    private val http = OkHttpClient()
    private val jsonType = "application/json; charset=utf-8".toMediaType()

    suspend fun record(context: Context, query: String, branchId: Int?) {
        withContext(Dispatchers.IO) {
            runCatching {
                val token = FcmRegistrationManager.getAccessToken(context)
                if (token.isBlank()) return@runCatching
                val body = JSONObject().put("query", query)
                    .put("branch_id", branchId ?: JSONObject.NULL)
                    .toString()
                val request = Request.Builder()
                    .url("https://baza.altaj-otdyh.ru/api/v1/analytics/search")
                    .header("Authorization", "Bearer $token")
                    .post(body.toRequestBody(jsonType))
                    .build()
                http.newCall(request).execute().use { /* ignore analytics errors */ }
            }
        }
    }
}
