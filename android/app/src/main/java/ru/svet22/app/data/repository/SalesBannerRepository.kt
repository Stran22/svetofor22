package ru.svet22.app.data.repository

import ru.svet22.app.data.model.SalesBannerSettings
import ru.svet22.app.data.remote.RetrofitClient

class SalesBannerRepository {
    suspend fun settings(): SalesBannerSettings =
        RetrofitClient.api.salesBanner().takeIf { it.success }?.data
            ?: error("Не удалось загрузить баннер SALES")
}
