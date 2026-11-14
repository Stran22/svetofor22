
package ru.svet22.app.data.repository

import kotlinx.coroutines.runBlocking
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import ru.svet22.app.data.remote.SvetApi

class ProductRepositoryTest {

    private lateinit var server: MockWebServer
    private lateinit var repository: ProductRepository

    @Before
    fun setUp() {
        server = MockWebServer()
        server.start()

        val api = Retrofit.Builder()
            .baseUrl(server.url("/api/v1/"))
            .addConverterFactory(GsonConverterFactory.create())
            .build()
            .create(SvetApi::class.java)

        repository = ProductRepository(api)
    }

    @After
    fun tearDown() {
        server.shutdown()
    }

    private fun jsonResponse(body: String) {
        server.enqueue(
            MockResponse()
                .setHeader("Content-Type", "application/json")
                .setBody(body)
        )
    }

    @Test
    fun getBranches_returnsBranches() = runBlocking {
        jsonResponse(
            """
            {
                "success": true,
                "data": [
                    {
                        "id": 1,
                        "code": "01",
                        "name": "На Волочаевской",
                        "address": "ул. Трофимова, дом 17"
                    }
                ],
                "meta": []
            }
            """.trimIndent()
        )

        val branches = repository.getBranches()

        assertEquals(1, branches.size)
        assertEquals("На Волочаевской", branches[0].name)
        assertEquals("01", branches[0].code)
    }

    @Test
    fun getProducts_returnsProducts() = runBlocking {
        jsonResponse(
            """
            {
                "success": true,
                "data": [
                    {
                        "id": 2,
                        "name": "Кефир 1%",
                        "manufacturer": "Россия",
                        "unit": "шт.",
                        "branch_id": 1,
                        "price": "75.12",
                        "quantity": "12.000"
                    }
                ],
                "meta": {
                    "pagination": {
                        "page": 1,
                        "per_page": 50,
                        "total": 1,
                        "total_pages": 1
                    }
                }
            }
            """.trimIndent()
        )

        val products = repository.getProducts(branchId = 1)

        assertEquals(1, products.size)
        assertEquals("Кефир 1%", products[0].name)
        assertEquals("75.12", products[0].price)
        assertEquals("Россия", products[0].manufacturer)

        val request = server.takeRequest()
        assertTrue(request.path!!.contains("branch_id=1"))
        assertTrue(request.path!!.contains("page=1"))
    }

    @Test
    fun getProducts_loadsAllPages() = runBlocking {
        jsonResponse(
            """
            {
                "success": true,
                "data": [
                    {"id": 1, "name": "Молоко", "price": "89.90"}
                ],
                "meta": {
                    "pagination": {
                        "page": 1,
                        "per_page": 1,
                        "total": 2,
                        "total_pages": 2
                    }
                }
            }
            """.trimIndent()
        )

        jsonResponse(
            """
            {
                "success": true,
                "data": [
                    {"id": 2, "name": "Кефир", "price": "75.12"}
                ],
                "meta": {
                    "pagination": {
                        "page": 2,
                        "per_page": 1,
                        "total": 2,
                        "total_pages": 2
                    }
                }
            }
            """.trimIndent()
        )

        val products = repository.getProducts(branchId = 1)

        assertEquals(2, products.size)
        assertEquals("Молоко", products[0].name)
        assertEquals("Кефир", products[1].name)

        val firstRequest = server.takeRequest()
        val secondRequest = server.takeRequest()

        assertTrue(firstRequest.path!!.contains("page=1"))
        assertTrue(secondRequest.path!!.contains("page=2"))
    }

    @Test(expected = ApiResponseException::class)
    fun getBranches_throwsExceptionWhenServerReportsFailure() {
        jsonResponse(
            """
            {
                "success": false,
                "data": null,
                "meta": []
            }
            """.trimIndent()
        )

        runBlocking {
            repository.getBranches()
        }
    }

    @Test(expected = ApiResponseException::class)
    fun getProducts_throwsExceptionWhenDataIsMissing() {
        jsonResponse(
            """
            {
                "success": true,
                "data": null,
                "meta": []
            }
            """.trimIndent()
        )

        runBlocking {
            repository.getProducts(branchId = 1)
        }
    }
}
