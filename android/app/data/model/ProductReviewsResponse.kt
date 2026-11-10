
package ru.svet22.app.data.model

import com.google.gson.annotations.SerializedName

// ===== НАЧАЛО БЛОКА: ОТДЕЛЬНЫЙ ОТЗЫВ =====

data class ProductReview(
    val id: Long,

    val rating: Int,

    @SerializedName("review_text")
    val reviewText: String? = null,

    @SerializedName("author_name")
    val authorName: String = "Покупатель",

    @SerializedName("created_at")
    val createdAt: String? = null
)

// ===== КОНЕЦ БЛОКА: ОТДЕЛЬНЫЙ ОТЗЫВ =====


// ===== НАЧАЛО БЛОКА: СТАТИСТИКА ОТЗЫВОВ =====

data class ProductReviewSummary(

    @SerializedName("reviews_count")
    val reviewsCount: Int = 0,

    val rating: Double? = null
)

// ===== КОНЕЦ БЛОКА: СТАТИСТИКА ОТЗЫВОВ =====


// ===== НАЧАЛО БЛОКА: ОТВЕТ API ОТЗЫВОВ =====

data class ProductReviewsResponse(

    val reviews: List<ProductReview> = emptyList(),

    val summary: ProductReviewSummary = ProductReviewSummary()
)

// ===== КОНЕЦ БЛОКА: ОТВЕТ API ОТЗЫВОВ =====
