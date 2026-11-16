package ru.svet22.app.ui.contest

import android.graphics.Bitmap
import android.graphics.BitmapFactory
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import ru.svet22.app.data.model.ContestDetail
import ru.svet22.app.data.repository.ContestRepository
import ru.svet22.app.push.FcmRegistrationManager
import java.net.URL

@Composable
fun ContestImage(path: String?, modifier: Modifier = Modifier) {
    val bitmap by produceState<Bitmap?>(initialValue = null, key1 = path) {
        value = if (path.isNullOrBlank()) null else withContext(Dispatchers.IO) {
            runCatching {
                val url = URL(URL("https://baza.altaj-otdyh.ru/"), path)
                val connection = url.openConnection().apply {
                    connectTimeout = 8000
                    readTimeout = 8000
                }
                connection.getInputStream().use { BitmapFactory.decodeStream(it) }
            }.getOrNull()
        }
    }
    if (bitmap != null) {
        Image(bitmap!!.asImageBitmap(), contentDescription = null, contentScale = ContentScale.Crop, modifier = modifier)
    } else {
        Box(modifier = modifier.background(Color(0xFFF2F2F2)))
    }
}

@Composable
fun ContestScreen(id: Int, isAuthenticated: Boolean, onLogin: () -> Unit) {
    val context = LocalContext.current
    val repository = remember { ContestRepository() }
    val scope = rememberCoroutineScope()
    var contest by remember(id) { mutableStateOf<ContestDetail?>(null) }
    var error by remember(id) { mutableStateOf<String?>(null) }
    var loading by remember(id) { mutableStateOf(true) }
    var joining by remember { mutableStateOf(false) }
    var accepted by remember(id) { mutableStateOf(false) }

    LaunchedEffect(id, isAuthenticated) {
        loading = true
        error = null
        try {
            val token = if (isAuthenticated) withContext(Dispatchers.IO) { FcmRegistrationManager.getAccessToken(context) } else null
            contest = repository.detail(id, token)
        } catch (e: Exception) {
            error = e.message ?: "Не удалось загрузить конкурс"
        } finally {
            loading = false
        }
    }
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp)) {
        if (loading) { CircularProgressIndicator(); return@Column }
        if (error != null) { Text(error!!, color = MaterialTheme.colorScheme.error); return@Column }
        val item = contest ?: return@Column
        Text(item.title, style = MaterialTheme.typography.headlineSmall)
        Spacer(Modifier.height(12.dp))
        ContestImage(item.detailImageUrl ?: item.bannerUrl, Modifier.fillMaxWidth().height(210.dp))
        Spacer(Modifier.height(12.dp))
        Text("Проводится: ${item.startsAt} — ${item.endsAt}", style = MaterialTheme.typography.bodySmall)
        if (!item.description.isNullOrBlank()) {
            Spacer(Modifier.height(16.dp))
            Text(item.description)
        }
        if (!item.prizeDescription.isNullOrBlank()) {
            Spacer(Modifier.height(16.dp))
            Text("Призы", style = MaterialTheme.typography.titleMedium)
            Text(item.prizeDescription)
        }
        if (!item.rules.isNullOrBlank()) {
            Spacer(Modifier.height(16.dp))
            Text("Правила конкурса", style = MaterialTheme.typography.titleMedium)
            Text(item.rules)
        }
        Spacer(Modifier.height(20.dp))
        if (item.isParticipant) {
            Text("Вы уже участвуете в этом конкурсе", color = Color(0xFF287A38))
        } else if (!isAuthenticated) {
            Button(onClick = onLogin, modifier = Modifier.fillMaxWidth()) { Text("Войти через VK для участия") }
        } else {
            Row {
                Checkbox(checked = accepted, onCheckedChange = { accepted = it })
                Text("Согласен с правилами конкурса", modifier = Modifier.padding(top = 12.dp))
            }
            Button(
                enabled = accepted && !joining,
                onClick = {
                    joining = true
                    error = null
                    scope.launch {
                        try {
                            val token = withContext(Dispatchers.IO) { FcmRegistrationManager.getAccessToken(context) }
                            if (token.isNullOrBlank()) error("Необходимо войти через VK")
                            else {
                                repository.join(id, token)
                                contest = item.copy(isParticipant = true)
                            }
                        } catch (e: Exception) {
                            error = e.message ?: "Ошибка регистрации"
                        } finally {
                            joining = false
                        }
                    }
                },
                modifier = Modifier.fillMaxWidth()
            ) { Text(if (joining) "Отправляем…" else "Участвовать") }
            if (error != null) Text(error!!, color = MaterialTheme.colorScheme.error)
        }
    }
}
