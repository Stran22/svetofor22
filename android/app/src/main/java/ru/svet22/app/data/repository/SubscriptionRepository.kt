package ru.svet22.app.data.repository

import android.content.Context
import android.util.Log
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import retrofit2.HttpException
import ru.svet22.app.data.model.CancelSubscriptionRequest
import ru.svet22.app.data.model.CreateSubscriptionRequest
import ru.svet22.app.data.model.TrackedSubscription
import ru.svet22.app.data.remote.RetrofitClient
import ru.svet22.app.push.FcmRegistrationManager

class SubscriptionRepository(private val context: Context) {
    private val api = RetrofitClient.api

    private suspend fun <T> authorized(action: suspend (String) -> T): T {
        val token = withContext(Dispatchers.IO) {
            FcmRegistrationManager.getAccessToken(context)
        }
        return try {
            action("Bearer $token")
        } catch (e: HttpException) {
            Log.w("SVET22_SUBS", "Subscription API HTTP ${e.code()}")
            if (e.code() != 401) throw e
            val renewed = withContext(Dispatchers.IO) {
                FcmRegistrationManager.refreshAccessToken(context)
            }
            action("Bearer $renewed")
        }
    }

    suspend fun list(): List<TrackedSubscription> = authorized { bearer ->
        val response = api.subscriptions(bearer)
        if (!response.success) error("Не удалось загрузить подписки")
        response.data?.subscriptions ?: emptyList()
    }

    suspend fun create(productId: Int, branchId: Int): TrackedSubscription =
        authorized { bearer ->
            val response = api.createSubscription(
                bearer, CreateSubscriptionRequest(productId, branchId)
            )
            if (!response.success) error("Не удалось создать подписку")
            response.data?.subscription ?: error("Сервер не вернул подписку")
        }

    suspend fun cancel(subscriptionId: Int) = authorized { bearer ->
        val response = api.cancelSubscription(
            bearer, CancelSubscriptionRequest(subscriptionId)
        )
        if (!response.success) error("Не удалось отменить подписку")
    }
}
