package ru.svet22.app.data.repository

import ru.svet22.app.data.model.ContestDetail
import ru.svet22.app.data.model.ContestSummary
import ru.svet22.app.data.model.ContestJoinRequest
import ru.svet22.app.data.remote.RetrofitClient

class ContestRepository {
    private val api = RetrofitClient.api
    suspend fun active(): List<ContestSummary> = api.homePromotions().data?.contests.orEmpty()
    suspend fun detail(id: Int, token: String?): ContestDetail =
        api.contestDetail(id, token?.takeIf { it.isNotBlank() }?.let { "Bearer $it" }).data
            ?: error("Не удалось получить данные конкурса")
    suspend fun join(id: Int, token: String) {
        val response = api.joinContest(id, "Bearer $token", ContestJoinRequest())
        if (!response.success) error("Не удалось зарегистрироваться в конкурсе")
    }
}
