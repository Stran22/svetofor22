package ru.svet22.app.ui.profile

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ru.svet22.app.auth.MyReview
import ru.svet22.app.auth.ProfileRepository
import ru.svet22.app.auth.VkProfile

@Composable
fun ProfileScreen(
    onVkLoginClick: () -> Unit = {},
    onLogoutClick: () -> Unit = {},
    vkLoginStatus: String = "",
    isAuthenticated: Boolean = false
) {
    val context = LocalContext.current.applicationContext
    var profile by remember { mutableStateOf<VkProfile?>(null) }
    var reviews by remember { mutableStateOf<List<MyReview>>(emptyList()) }
    var loading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var refreshKey by remember { mutableIntStateOf(0) }

    LaunchedEffect(isAuthenticated, refreshKey) {
        profile = null
        reviews = emptyList()
        error = null
        if (isAuthenticated) {
            loading = true
            try {
                profile = ProfileRepository.profile(context)
                reviews = ProfileRepository.reviews(context)
            } catch (e: Exception) {
                error = e.message ?: "Не удалось загрузить профиль."
            } finally {
                loading = false
            }
        }
    }

    LazyColumn(
        modifier = Modifier.fillMaxSize().padding(horizontal = 20.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp)
    ) {
        item {
            Spacer(Modifier.height(12.dp))
            Text("Профиль", style = MaterialTheme.typography.headlineMedium)
        }
        if (!isAuthenticated) {
            item {
                Text("Войдите через VK ID, чтобы видеть свои отзывы и их статус.")
                Button(onClick = onVkLoginClick) { Text("Войти через VK ID") }
                Text(vkLoginStatus, style = MaterialTheme.typography.bodySmall)
            }
        } else {
            item {
                Card(modifier = Modifier.fillMaxWidth()) {
                    Row(
                        modifier = Modifier.padding(16.dp),
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.spacedBy(12.dp)
                    ) {
                        Surface(shape = CircleShape, color = Color(0xFF1976D2), modifier = Modifier.size(52.dp)) {
                            androidx.compose.foundation.layout.Box(contentAlignment = Alignment.Center) {
                                Text(
                                    profile?.name?.take(1)?.uppercase() ?: "VK",
                                    color = Color.White,
                                    style = MaterialTheme.typography.titleLarge
                                )
                            }
                        }
                        Column {
                            Text(profile?.name ?: "Загрузка профиля…", fontWeight = FontWeight.Bold)
                            Text("Авторизован через VK ID", style = MaterialTheme.typography.bodySmall)
                        }
                    }
                }
            }
            item {
                TextButton(onClick = onLogoutClick) { Text("Выйти из аккаунта") }
            }
            item {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text("Мои отзывы", style = MaterialTheme.typography.titleLarge, modifier = Modifier.weight(1f))
                    TextButton(onClick = { refreshKey++ }, enabled = !loading) { Text("Обновить") }
                }
                if (loading) CircularProgressIndicator(modifier = Modifier.padding(12.dp))
                error?.let {
                    Text(it, color = MaterialTheme.colorScheme.error)
                    TextButton(onClick = { refreshKey++ }) { Text("Повторить") }
                }
                if (!loading && error == null && reviews.isEmpty()) {
                    Text("Вы ещё не оставляли отзывов.")
                }
            }
            items(reviews, key = { it.id }) { review ->
                Card(modifier = Modifier.fillMaxWidth()) {
                    Column(modifier = Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        Text(review.productName, fontWeight = FontWeight.SemiBold)
                        Text("★".repeat(review.rating) + "☆".repeat(5 - review.rating),
                            color = Color(0xFFFFB300), style = MaterialTheme.typography.titleMedium)
                        val statusLabel = when (review.status) {
                            "published" -> "Опубликован"
                            "rejected" -> "Отклонён"
                            else -> "На модерации"
                        }
                        Text("Статус: $statusLabel", style = MaterialTheme.typography.labelMedium)
                        if (review.text.isNotBlank()) Text(review.text)
                        Text(review.createdAt, style = MaterialTheme.typography.bodySmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                }
            }
        }
        item { Spacer(Modifier.height(20.dp)) }
    }
}
