package ru.svet22.app.ui.tracked

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import ru.svet22.app.data.model.TrackedSubscription
import ru.svet22.app.data.repository.SubscriptionRepository

@Composable
fun TrackedScreen() {
    val context = LocalContext.current
    val repository = remember(context) { SubscriptionRepository(context.applicationContext) }
    val scope = rememberCoroutineScope()
    var subscriptions by remember { mutableStateOf<List<TrackedSubscription>>(emptyList()) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }
    var busyId by remember { mutableStateOf<Int?>(null) }
    var refresh by remember { mutableIntStateOf(0) }

    LaunchedEffect(refresh) {
        loading = true
        error = null
        try {
            subscriptions = repository.list()
        } catch (e: Exception) {
            error = "Не удалось загрузить подписки. Проверьте соединение."
        } finally {
            loading = false
        }
    }
    Column(Modifier.fillMaxSize().padding(16.dp)) {
        Text("Отслеживаемое", style = MaterialTheme.typography.headlineSmall)
        Spacer(Modifier.height(12.dp))
        if (loading) CircularProgressIndicator()
        if (error != null) {
            Text(error!!, color = MaterialTheme.colorScheme.error)
            TextButton(onClick = { refresh++ }) { Text("Повторить") }
        }
        if (!loading && error == null && subscriptions.none { it.status == "active" }) {
            Text("Пока нет активных подписок. Выберите отсутствующий товар в каталоге.")
        }
        LazyColumn(verticalArrangement = Arrangement.spacedBy(8.dp)) {
            items(subscriptions.filter { it.status == "active" }, key = { it.id }) { item ->
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(14.dp)) {
                        Text(item.productName ?: "Товар №${item.productId}", style = MaterialTheme.typography.titleMedium)
                        Text(item.branchName ?: "Магазин №${item.branchId}")
                        TextButton(
                            enabled = busyId != item.id,
                            onClick = {
                                busyId = item.id
                                scope.launch {
                                    try {
                                        repository.cancel(item.id)
                                        subscriptions = subscriptions.filterNot { it.id == item.id }
                                        error = null
                                    } catch (e: Exception) {
                                        error = "Не удалось отменить подписку."
                                    } finally {
                                        busyId = null
                                    }
                                }
                            }
                        ) { Text(if (busyId == item.id) "Отмена..." else "Отменить отслеживание") }
                    }
                }
            }
        }
    }
}
