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

/** Best-effort anonymous-or-authenticated telemetry. Never blocks catalog UI. */
object VisitorAnalyticsRepository {
    private val http = OkHttpClient()
    private val jsonType = "application/json; charset=utf-8".toMediaType()

    suspend fun record(context: Context, event: String, id: Int? = null, branchId: Int? = null) {
        withContext(Dispatchers.IO) {
            runCatching {
                val token = FcmRegistrationManager.getAccessToken(context)
                if (token.isBlank()) return@runCatching
                val body = JSONObject().put("event_type", event)
                if (event == "product_view" && id != null) body.put("product_id", id)
                if (event == "category_click" && id != null) body.put("category_id", id)
                if (branchId != null) body.put("branch_id", branchId)
                val request = Request.Builder()
                    .url("https://baza.altaj-otdyh.ru/api/v1/analytics/event")
                    .header("Authorization", "Bearer $token")
                    .post(body.toString().toRequestBody(jsonType))
                    .build()
                http.newCall(request).execute().use { /* analytics failures are non-fatal */ }
            }
        }
    }
}
