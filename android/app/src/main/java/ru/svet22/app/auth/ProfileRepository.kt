package ru.svet22.app.auth

import android.content.Context
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.OkHttpClient
import okhttp3.Request
import org.json.JSONObject
import ru.svet22.app.push.FcmRegistrationManager
import java.io.IOException

data class VkProfile(val name: String, val provider: String)
data class MyReview(
    val id: Int,
    val productName: String,
    val rating: Int,
    val text: String,
    val status: String,
    val createdAt: String
)

/** Reads only the current user's data, never another user's reviews. */
object ProfileRepository {
    private const val base = "https://baza.altaj-otdyh.ru/api/v1"
    private val client = OkHttpClient()

    private suspend fun get(context: Context, path: String): JSONObject = withContext(Dispatchers.IO) {
        val bearer = FcmRegistrationManager.getAccessToken(context)
        val request = Request.Builder().url("$base/$path")
            .header("Authorization", "Bearer $bearer").get().build()
        client.newCall(request).execute().use { response ->
            val json = JSONObject(response.body?.string() ?: "{}")
            if (!response.isSuccessful || !json.optBoolean("success")) {
                throw IOException("Не удалось загрузить данные (HTTP ${response.code}).")
            }
            json.optJSONObject("data") ?: throw IOException("Некорректный ответ сервера.")
        }
    }

    suspend fun profile(context: Context): VkProfile {
        val data = get(context, "me")
        if (data.optInt("is_guest", 1) != 0) throw IOException("Требуется вход через VK ID.")
        return VkProfile(data.optString("display_name").ifBlank { "Пользователь VK ID" }, "VK ID")
    }

    suspend fun reviews(context: Context): List<MyReview> {
        val data = get(context, "me/reviews")
        val array = data.optJSONArray("reviews") ?: return emptyList()
        return (0 until array.length()).map { index ->
            val item = array.getJSONObject(index)
            MyReview(
                id = item.optInt("id"),
                productName = item.optString("product_name", "Товар"),
                rating = item.optInt("rating").coerceIn(1, 5),
                text = item.optString("review_text").takeUnless { it == "null" } ?: "",
                status = item.optString("status"),
                createdAt = item.optString("created_at")
            )
        }
    }
}
