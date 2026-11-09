package ru.svet22.app.data.repository

import ru.svet22.app.data.model.ApiEnvelope
import ru.svet22.app.data.model.Branch
import ru.svet22.app.data.model.Category
import ru.svet22.app.data.model.Product
import ru.svet22.app.data.remote.RetrofitClient
import ru.svet22.app.data.remote.SvetApi

class ProductRepository(
    private val api: SvetApi = RetrofitClient.api
) {
    suspend fun getBranches(): List<Branch> =
        api.branches().requireData("филиалов")

    suspend fun getCategories(): List<Category> =
        api.categories().requireData("категорий")

    suspend fun getProducts(branchId: Int, categoryId: Int? = null): List<Product> {
        val allProducts = mutableListOf<Product>()
        var page = 1
        val pageSize = 50
        while (true) {
            val response = api.products(
                branchId = branchId,
                page = page,
                perPage = pageSize,
                categoryId = categoryId
            )
            val batch = response.requireData("товаров")
            allProducts.addAll(batch)
            // The backend currently returns meta.page, not meta.pagination.total_pages.
            if (batch.size < pageSize) break
            page++
        }
        return allProducts
    }

    private fun <T> ApiEnvelope<T>.requireData(resourceName: String): T {
        if (!success) {
            throw ApiResponseException("Сервер сообщил об ошибке при загрузке: $resourceName.")
        }
        return data ?: throw ApiResponseException("Сервер не вернул данные: $resourceName.")
    }
}

class ApiResponseException(message: String) : Exception(message)
