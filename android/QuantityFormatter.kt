package ru.svet22.app.ui.catalog

import java.math.BigDecimal

fun formatQuantity(value: String?): String? {
    if (value.isNullOrBlank()) return null

    return try {
        BigDecimal(value.trim())
            .stripTrailingZeros()
            .toPlainString()
    } catch (e: NumberFormatException) {
        value
    }
}