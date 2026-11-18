package ru.svet22.app.data.model

import com.google.gson.annotations.SerializedName

data class SalesBannerSettings(
    @SerializedName("is_enabled") val isEnabled: Boolean = true,
    val mode: String = "text",
    val heading: String = "SALES",
    @SerializedName("cta_text") val ctaText: String = "SHOP NOW",
    @SerializedName("right_title") val rightTitle: String = "BIGGEST SALES",
    @SerializedName("right_subtitle") val rightSubtitle: String = "UP TO 70% OFF",
    @SerializedName("product_id") val productId: Int? = null,
    @SerializedName("product_name") val productName: String? = null,
    @SerializedName("image_url") val imageUrl: String? = null
)
