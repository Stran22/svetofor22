package ru.svet22.app.data.model

import com.google.gson.annotations.SerializedName

data class Category(
    val id: Int,
    @SerializedName("parent_id") val parentId: Int? = null,
    val name: String,
    val slug: String? = null,
    @SerializedName("image_url") val imageUrl: String? = null,
    @SerializedName("sort_order") val sortOrder: Int = 0
)
