package ru.svet22.app.data.model

import com.google.gson.annotations.SerializedName

data class ProductDetailSettings(
    val id: Int,
    @SerializedName("can_track") val canTrack: Boolean = false,
    @SerializedName("hide_store_stock") val hideStoreStock: Boolean = false
)
