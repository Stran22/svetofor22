package ru.svet22.app.data.model

import com.google.gson.annotations.SerializedName

data class HomePromotions(
    @SerializedName("contests") val contests: List<ContestSummary> = emptyList()
)
data class ContestSummary(
    val id: Int,
    val title: String,
    @SerializedName("banner_url") val bannerUrl: String?,
    @SerializedName("starts_at") val startsAt: String,
    @SerializedName("ends_at") val endsAt: String
)
data class ContestDetail(
    val id: Int,
    val title: String,
    val description: String?,
    val rules: String?,
    @SerializedName("prize_description") val prizeDescription: String?,
    @SerializedName("banner_url") val bannerUrl: String?,
    @SerializedName("detail_image_url") val detailImageUrl: String?,
    @SerializedName("starts_at") val startsAt: String,
    @SerializedName("ends_at") val endsAt: String,
    @SerializedName("is_participant") val isParticipant: Boolean = false
)
data class ContestJoinRequest(@SerializedName("accept_rules") val acceptRules: Boolean = true)
data class ContestJoinResult(@SerializedName("contest_id") val contestId: Int, @SerializedName("is_participant") val isParticipant: Boolean)
