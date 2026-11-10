package ru.svet22.app.data.model

import com.google.gson.annotations.SerializedName

data class TrackedSubscription(
    val id: Int,
    @SerializedName("product_id") val productId: Int,
    @SerializedName("branch_id") val branchId: Int,
    val status: String,
    @SerializedName("product_name") val productName: String? = null,
    @SerializedName("branch_name") val branchName: String? = null
)
data class SubscriptionsResponse(val subscriptions: List<TrackedSubscription>)
data class SubscriptionResponse(val subscription: TrackedSubscription)
data class CancelSubscriptionResponse(
    @SerializedName("subscription_id") val subscriptionId: Int,
    val status: String
)
data class CreateSubscriptionRequest(
    @SerializedName("product_id") val productId: Int,
    @SerializedName("branch_id") val branchId: Int
)
data class CancelSubscriptionRequest(@SerializedName("subscription_id") val subscriptionId: Int)
