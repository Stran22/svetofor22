package ru.svet22.app.data.remote

import retrofit2.http.Body
import retrofit2.http.Header
import retrofit2.http.POST
import retrofit2.http.GET
import retrofit2.http.Path
import retrofit2.http.Query
import ru.svet22.app.data.model.ApiEnvelope
import ru.svet22.app.data.model.Branch
import ru.svet22.app.data.model.Category
import ru.svet22.app.data.model.Product
import ru.svet22.app.data.model.ProductReviewsResponse
import ru.svet22.app.data.model.SubscriptionsResponse
import ru.svet22.app.data.model.SubscriptionResponse
import ru.svet22.app.data.model.CancelSubscriptionResponse
import ru.svet22.app.data.model.CreateSubscriptionRequest
import ru.svet22.app.data.model.CancelSubscriptionRequest

interface SvetApi {
    @GET("subscriptions")
    suspend fun subscriptions(@Header("Authorization") bearer: String): ApiEnvelope<SubscriptionsResponse>

    @POST("subscriptions")
    suspend fun createSubscription(
        @Header("Authorization") bearer: String,
        @Body body: CreateSubscriptionRequest
    ): ApiEnvelope<SubscriptionResponse>

    @POST("subscriptions/cancel")
    suspend fun cancelSubscription(
        @Header("Authorization") bearer: String,
        @Body body: CancelSubscriptionRequest
    ): ApiEnvelope<CancelSubscriptionResponse>

    @GET("branches")
    suspend fun branches(): ApiEnvelope<List<Branch>>

    @GET("categories")
    suspend fun categories(): ApiEnvelope<List<Category>>

    @GET("products")
    suspend fun products(
        @Query("branch_id") branchId: Int,
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 50,
        @Query("category_id") categoryId: Int? = null
    ): ApiEnvelope<List<Product>>

    @GET("products/{id}/reviews")
    suspend fun productReviews(
        @Path("id") productId: Int,
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 20
    ): ApiEnvelope<ProductReviewsResponse>
}
