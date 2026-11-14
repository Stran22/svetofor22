package ru.svet22.app.data.model

import com.google.gson.annotations.SerializedName

data class Product(
    val id: Int,
    val name: String,
    val description: String? = null,
    val barcode: String? = null,
    val article: String? = null,
    @SerializedName("category_id")
    val categoryId: Int? = null,
    @SerializedName("category_name")
    val categoryName: String? = null,
    val manufacturer: String? = null,
    val unit: String? = null,
    @SerializedName("image_url")
    val imageUrl: String? = null,
    @SerializedName("branch_id")
    val branchId: Int? = null,
    val price: String? = null,
    val quantity: String? = null,
    @SerializedName("imported_on")
    val importedOn: String? = null
)
