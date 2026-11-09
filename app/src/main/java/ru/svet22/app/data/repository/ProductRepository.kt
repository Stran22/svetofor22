package ru.svet22.app.data.repository

import ru.svet22.app.data.model.ApiEnvelope
import ru.svet22.app.data.model.Branch
import ru.svet22.app.data.model.Product
import ru.svet22.app.data.remote.RetrofitClient
import ru.svet22.app.data.remote.SvetApi

class ProductRepository(
    private val api: SvetApi = RetrofitClient.api
) {
    suspend fun getBranches(): List<Branch> {
        val response = api.branches()
        return response.requireData("филиалов")
    }

    suspend fun getProducts(branchId: Int): List<Product> {
        val allProducts = mutableListOf<Product>()
        var page = 1
        var totalPages = 1

        while (page <= totalPages) {
            val response = api.products(
                branchId = branchId,
                page = page,
                perPage = 50
            )
            val pageProducts = response.requireData("товаров")
            allProducts.addAll(pageProducts)
            totalPages = response.getTotalPages(page).coerceAtLeast(page)
            page++
        }
        return allProducts
    }

    private fun <T> ApiEnvelope<T>.requireData(resourceName: String): T {
        if (!success) {
            throw ApiResponseException(
                "Сервер сообщил об ошибке при загрузке: $resourceName."
            )
        }
        return data ?: throw ApiResponseException(
            "Сервер не вернул данные: $resourceName."
        )
    }

    private fun ApiEnvelope<*>.getTotalPages(currentPage: Int): Int {
        val pagination = meta
            ?.takeIf { it.isJsonObject }
            ?.asJsonObject
            ?.get("pagination")
            ?.takeIf { it.isJsonObject }
            ?.asJsonObject

        val totalPages = pagination
            ?.get("total_pages")
            ?.takeIf { !it.isJsonNull }
            ?.asInt

        return totalPages ?: currentPage
    }
}

class ApiResponseException(message: String) : Exception(message)
