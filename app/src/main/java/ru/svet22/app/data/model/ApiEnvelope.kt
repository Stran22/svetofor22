package ru.svet22.app.data.model

import com.google.gson.JsonElement

data class ApiEnvelope<T>(
    val success: Boolean,
    val data: T?,
    val meta: JsonElement?
)
