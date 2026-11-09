package ru.svet22.app.ui.catalog

// ===== НАЧАЛО БЛОКА: Импорты =====
import android.graphics.BitmapFactory
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ImageNotSupported
import androidx.compose.material.icons.filled.StarBorder
import androidx.compose.material.icons.filled.Star
import androidx.compose.material.icons.filled.NotificationsActive
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Divider
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Tab
import androidx.compose.material3.TabRow
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.ui.platform.LocalContext
import kotlinx.coroutines.launch
import ru.svet22.app.data.repository.SubscriptionRepository
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.produceState
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.text.font.FontWeight
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.async
import kotlinx.coroutines.awaitAll
import kotlinx.coroutines.coroutineScope
import kotlinx.coroutines.withContext
import ru.svet22.app.data.model.Product
import ru.svet22.app.data.model.Branch
import ru.svet22.app.data.repository.ProductRepository
import ru.svet22.app.data.model.ProductReviewsResponse
import ru.svet22.app.data.remote.RetrofitClient
import java.net.HttpURLConnection
import java.net.URL
// ===== КОНЕЦ БЛОКА: Импорты =====


// ===== НАЧАЛО БЛОКА: Цвета карточки =====
private val ProductYellow = Color(0xFFFFEB4D)
private val ProductDark = Color(0xFF252525)
private val ProductLightGray = Color(0xFFF7F7F7)
private val ProductRed = Color(0xFFE53935)
// ===== КОНЕЦ БЛОКА: Цвета карточки =====


// ===== НАЧАЛО БЛОКА: Настройки отображения характеристик =====
private object ProductDetailFieldSettings {
    const val showManufacturer = true
    const val showCategory = true

    const val showBarcode = false
    const val showArticle = false
    const val showUnit = false
    const val showBranch = false
    const val showImportedOn = false
}
// ===== КОНЕЦ БЛОКА: Настройки отображения характеристик =====


// ===== НАЧАЛО БЛОКА: Основной экран карточки товара =====
@Composable
fun ProductDetailScreen(
    product: Product,
    modifier: Modifier = Modifier,

    // Переход в профиль после нажатия "Войти".
    // Подключим через AppNavigation.kt на следующем шаге.
    onLoginClick: () -> Unit = {},
    branchName: String? = null,
    branches: List<Branch> = emptyList()
) {

    // ===== НАЧАЛО БЛОКА: Состояние вкладок =====
    var selectedTab by remember(product.id) {
        mutableIntStateOf(0)
    }
    // ===== КОНЕЦ БЛОКА: Состояние вкладок =====

    // ===== НАЧАЛО БЛОКА: Состояние диалога авторизации =====
    var showLoginDialog by remember {
        mutableStateOf(false)
    }
    var showTrackingDialog by remember(product.id) { mutableStateOf(false) }
    val appContext = LocalContext.current.applicationContext
    val subscriptionRepository = remember(appContext) {
        SubscriptionRepository(appContext)
    }
    val trackingScope = rememberCoroutineScope()
    var trackingBusy by remember(product.id) { mutableStateOf(false) }
    var trackingMessage by remember(product.id) { mutableStateOf<String?>(null) }
    var trackedBranches by remember(product.id) { mutableStateOf<Set<Int>>(emptySet()) }
    var trackingError by remember(product.id) { mutableStateOf<String?>(null) }
    LaunchedEffect(product.id) {
        try {
            trackedBranches = subscriptionRepository.list()
                .filter { it.productId == product.id && it.status == "active" }
                .map { it.branchId }.toSet()
        } catch (_: Exception) { /* The user can retry when subscribing. */ }
    }
    // ===== КОНЕЦ БЛОКА: Состояние диалога авторизации =====

    // Остатки по каждому филиалу загружаются независимо.
    var branchProducts by remember(product.id) { mutableStateOf<Map<Int, Product>>(emptyMap()) }
    var stockLoading by remember(product.id) { mutableStateOf(true) }
    var stockFailed by remember(product.id) { mutableStateOf<Set<Int>>(emptySet()) }
    val repository = remember { ProductRepository() }
    LaunchedEffect(product.id, branches.map { it.id }) {
        stockLoading = true
        branchProducts = emptyMap()
        stockFailed = emptySet()
        try {
            val results = coroutineScope {
                branches.map { branch ->
                    async {
                        try {
                            // A product may be absent from a branch's catalog; don't report that as zero.
                            val match = repository.getProducts(branch.id, product.categoryId)
                                .firstOrNull { it.id == product.id }
                            Triple(branch.id, match, false)
                        } catch (e: kotlinx.coroutines.CancellationException) {
                            throw e
                        } catch (_: Exception) {
                            Triple(branch.id, null, true)
                        }
                    }
                }.awaitAll()
            }
            branchProducts = results.mapNotNull { result -> result.second?.let { result.first to it } }.toMap()
            stockFailed = results.filter { it.third }.map { it.first }.toSet()
        } finally {
            stockLoading = false
        }
    }

    // ===== НАЧАЛО БЛОКА: ЗАГРУЗКА ОТЗЫВОВ =====
    var reviewsResponse by remember(product.id) { mutableStateOf<ProductReviewsResponse?>(null) }
    var reviewsLoading by remember(product.id) { mutableStateOf(true) }
    var reviewsError by remember(product.id) { mutableStateOf<String?>(null) }

    LaunchedEffect(product.id) {
        reviewsLoading = true
        reviewsError = null
        reviewsResponse = null
        try {
            val result = RetrofitClient.api.productReviews(productId = product.id)
            if (result.success && result.data != null) {
                reviewsResponse = result.data
            } else {
                reviewsError = "Не удалось получить отзывы."
            }
        } catch (e: kotlinx.coroutines.CancellationException) {
            throw e
        } catch (_: Exception) {
            reviewsError = "Ошибка загрузки отзывов. Проверьте интернет-соединение."
        } finally {
            reviewsLoading = false
        }
    }
    // ===== КОНЕЦ БЛОКА: ЗАГРУЗКА ОТЗЫВОВ =====

    Column(
        modifier = modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(16.dp)
    ) {

        Text(
            text = "СВЕТОФОР",
            color = ProductRed,
            fontSize = 25.sp,
            fontWeight = FontWeight.ExtraBold
        )
        Spacer(Modifier.height(12.dp))

        // ===== НАЧАЛО БЛОКА: Фотография товара =====
        ProductDetailImage(
            imageUrl = product.imageUrl,
            productName = product.name
        )
        // ===== КОНЕЦ БЛОКА: Фотография товара =====

        Spacer(Modifier.height(16.dp))

        // ===== НАЧАЛО БЛОКА: Цена товара =====
        Box(
            modifier = Modifier
                .fillMaxWidth()
                .background(
                    color = ProductYellow,
                    shape = RoundedCornerShape(16.dp)
                )
                .padding(horizontal = 20.dp, vertical = 14.dp)
        ) {
            Text(
                text = product.price
                    ?.takeIf { it.isNotBlank() }
                    ?.let { "$it ₽" }
                    ?: "Цена не указана",
                style = MaterialTheme.typography.headlineLarge,
                color = ProductDark
            )
        }
        // ===== КОНЕЦ БЛОКА: Цена товара =====

        Spacer(Modifier.height(16.dp))

        // ===== НАЧАЛО БЛОКА: Название товара =====
        Text(
            text = product.name,
            style = MaterialTheme.typography.headlineSmall,
            color = ProductDark
        )
        // ===== КОНЕЦ БЛОКА: Название товара =====

        Spacer(Modifier.height(12.dp))

        // ===== НАЧАЛО БЛОКА: Остатки во всех филиалах =====
        Text(
            text = "Цены и остатки в магазинах",
            style = MaterialTheme.typography.titleMedium,
            fontWeight = FontWeight.Bold,
            color = ProductDark
        )
        Spacer(Modifier.height(8.dp))
        if (branches.isEmpty()) {
            Text("Список филиалов пока недоступен")
        } else {
            branches.forEach { branch ->
                val branchProduct = branchProducts[branch.id]
                Row(
                    modifier = Modifier.fillMaxWidth().padding(vertical = 10.dp),
                    horizontalArrangement = Arrangement.SpaceBetween,
                    verticalAlignment = Alignment.CenterVertically
                ) {
                    Column(modifier = Modifier.weight(1f)) {
                        Text(
                            text = branch.name + if (branch.id == product.branchId) " • выбран" else "",
                            style = MaterialTheme.typography.bodyMedium,
                            fontWeight = if (branch.id == product.branchId) FontWeight.Bold else FontWeight.Normal
                        )
                        Text(
                            text = when {
                                stockLoading -> "Загрузка..."
                                branch.id in stockFailed -> "Не удалось загрузить данные"
                                branchProduct == null -> "Товар не найден в каталоге филиала"
                                else -> formatQuantity(branchProduct.quantity)?.let { "Остаток: $it шт." }
                                    ?: "Остаток неизвестен"
                            },
                            style = MaterialTheme.typography.bodySmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant
                        )
                    }
                    Text(
                        text = when {
                            stockLoading -> "..."
                            branch.id in stockFailed || branchProduct == null -> "—"
                            else -> branchProduct.price?.takeIf { it.isNotBlank() }?.let { "$it ₽" }
                                ?: "Цена не указана"
                        },
                        style = MaterialTheme.typography.titleMedium,
                        fontWeight = FontWeight.Bold,
                        color = ProductDark
                    )
                }
                Divider()
            }
        }

        // ===== КОНЕЦ БЛОКА: Остатки во всех филиалах =====

        Spacer(Modifier.height(16.dp))
        Button(
            onClick = { showTrackingDialog = true },
            modifier = Modifier.fillMaxWidth().height(54.dp),
            shape = RoundedCornerShape(14.dp),
            colors = androidx.compose.material3.ButtonDefaults.buttonColors(
                containerColor = Color(0xFF2E7D32),
                contentColor = Color.White
            )
        ) {
            Icon(Icons.Default.NotificationsActive, contentDescription = null)
            Spacer(Modifier.size(10.dp))
            Text("Следить за товаром", fontWeight = FontWeight.Bold)
        }
        Spacer(Modifier.height(6.dp))
        Text(
            "Выберите магазин, где товара нет в наличии. Уведомим о поступлении.",
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant
        )
        Spacer(Modifier.height(24.dp))

        // ===== НАЧАЛО БЛОКА: Переключатель вкладок =====
        TabRow(
            selectedTabIndex = selectedTab
        ) {
            Tab(
                selected = selectedTab == 0,
                onClick = { selectedTab = 0 },
                text = { Text("Описание") }
            )

            Tab(
                selected = selectedTab == 1,
                onClick = { selectedTab = 1 },
                text = { Text("Характеристики") }
            )
        }
        // ===== КОНЕЦ БЛОКА: Переключатель вкладок =====

        Spacer(Modifier.height(16.dp))

        // ===== НАЧАЛО БЛОКА: Содержимое выбранной вкладки =====
        when (selectedTab) {
            0 -> ProductDescriptionTab(product)
            1 -> ProductCharacteristicsTab(product)
        }
        // ===== КОНЕЦ БЛОКА: Содержимое выбранной вкладки =====

        Spacer(Modifier.height(28.dp))
        Divider()
        Spacer(Modifier.height(20.dp))

        // ===== НАЧАЛО БЛОКА: Общий блок отзывов =====
        ProductReviewsSection(
            reviewsResponse = reviewsResponse,
            isLoading = reviewsLoading,
            errorMessage = reviewsError,
            onLeaveReviewClick = {
                showLoginDialog = true
            }
        )
        // ===== КОНЕЦ БЛОКА: Общий блок отзывов =====
    }

    if (showTrackingDialog) {
        AlertDialog(
            onDismissRequest = { if (!trackingBusy) showTrackingDialog = false },
            title = { Text("Следить за товаром") },
            text = {
                Column {
                    Text("Выберите филиал, в котором товара пока нет:")
                    Spacer(Modifier.height(8.dp))
                    branches.forEach { branch ->
                        val stock = branchProducts[branch.id]
                        val quantity = stock?.quantity?.toString()?.toDoubleOrNull()
                        val availableToTrack = !stockLoading &&
                            branch.id !in stockFailed && stock != null &&
                            quantity != null && quantity <= 0.0
                        val tracked = branch.id in trackedBranches
                        TextButton(
                            enabled = availableToTrack && !tracked && !trackingBusy,
                            onClick = {
                                trackingBusy = true
                                trackingError = null
                                trackingScope.launch {
                                    try {
                                        subscriptionRepository.create(product.id, branch.id)
                                        trackedBranches = trackedBranches + branch.id
                                        trackingMessage = "Подписка оформлена: ${branch.name}"
                                        showTrackingDialog = false
                                    } catch (e: Exception) {
                                        trackingError = "Не удалось оформить подписку. Проверьте наличие товара и соединение."
                                    } finally {
                                        trackingBusy = false
                                    }
                                }
                            }
                        ) {
                            Text(branch.name + when {
                                tracked -> " — отслеживается"
                                availableToTrack -> " — подписаться"
                                else -> " — подписка недоступна"
                            })
                        }
                    }
                    if (trackingBusy) CircularProgressIndicator()
                    trackingError?.let {
                        Text(it, color = MaterialTheme.colorScheme.error)
                    }
                }
            },
            confirmButton = {
                TextButton(onClick = { showTrackingDialog = false }, enabled = !trackingBusy) {
                    Text("Закрыть")
                }
            }
        )
    }
    trackingMessage?.let { message ->
        AlertDialog(
            onDismissRequest = { trackingMessage = null },
            title = { Text("Готово") },
            text = { Text(message) },
            confirmButton = {
                TextButton(onClick = { trackingMessage = null }) { Text("Понятно") }
            }
        )
    }

    // ===== НАЧАЛО БЛОКА: Диалог авторизации =====
    if (showLoginDialog) {
        AlertDialog(
            onDismissRequest = { showLoginDialog = false },
            shape = RoundedCornerShape(28.dp),
            containerColor = Color.White,
            title = {
                Column(
                    modifier = Modifier.fillMaxWidth(),
                    horizontalAlignment = Alignment.CenterHorizontally,
                    verticalArrangement = Arrangement.spacedBy(12.dp)
                ) {
                    Text(
                        text = "Поделитесь впечатлением!",
                        style = MaterialTheme.typography.titleLarge,
                        color = ProductDark
                    )
                }
            },
            text = {
                Text(
                    text = "Ваш отзыв поможет другим покупателям выбрать товар. Для публикации сначала войдите в свой аккаунт.",
                    style = MaterialTheme.typography.bodyMedium,
                    color = Color(0xFF5F6368)
                )
            },
            confirmButton = {
                Button(
                    onClick = {
                        showLoginDialog = false
                        onLoginClick()
                    },
                    modifier = Modifier
                        .fillMaxWidth()
                        .padding(horizontal = 16.dp),
                    shape = RoundedCornerShape(14.dp),
                    colors = androidx.compose.material3.ButtonDefaults.buttonColors(
                        containerColor = ProductDark,
                        contentColor = Color.White
                    )
                ) {
                    Text("Войти и оставить отзыв")
                }
            },
            dismissButton = {
                TextButton(
                    onClick = { showLoginDialog = false },
                    modifier = Modifier.fillMaxWidth()
                ) {
                    Text("Может быть, позже", color = Color(0xFF6F6F6F))
                }
            }
        )
    }
    // ===== КОНЕЦ БЛОКА: Диалог авторизации =====
}
// ===== КОНЕЦ БЛОКА: Основной экран карточки товара =====


// ===== НАЧАЛО БЛОКА: Общий блок отзывов =====
@Composable
private fun ProductReviewsSection(
    reviewsResponse: ProductReviewsResponse?,
    isLoading: Boolean,
    errorMessage: String?,
    onLeaveReviewClick: () -> Unit
) {
    val summary = reviewsResponse?.summary
    val reviews = reviewsResponse?.reviews.orEmpty()

    Column(modifier = Modifier.fillMaxWidth()) {
        Row(
            modifier = Modifier.fillMaxWidth(),
            horizontalArrangement = Arrangement.SpaceBetween,
            verticalAlignment = Alignment.CenterVertically
        ) {
            Text(
                text = if (isLoading || errorMessage != null) "Отзывы"
                       else "Отзывы (${summary?.reviewsCount ?: 0})",
                style = MaterialTheme.typography.titleLarge,
                color = ProductDark
            )
            Row(verticalAlignment = Alignment.CenterVertically) {
                val rating = summary?.rating
                Icon(
                    imageVector = if (rating != null) Icons.Default.Star else Icons.Default.StarBorder,
                    contentDescription = null,
                    tint = if (rating != null) Color(0xFFFFB300) else Color.Gray
                )
                Spacer(Modifier.size(4.dp))
                Text(
                    text = rating?.let {
                        String.format(java.util.Locale.forLanguageTag("ru-RU"), "%.1f", it)
                    } ?: "Нет оценок",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant
                )
            }
        }

        Spacer(Modifier.height(16.dp))

        when {
            isLoading -> {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    CircularProgressIndicator(modifier = Modifier.size(28.dp))
                    Spacer(Modifier.size(12.dp))
                    Text("Загрузка отзывов...")
                }
            }
            errorMessage != null -> Text(
                text = errorMessage,
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.error
            )
            reviews.isEmpty() -> Text(
                text = "Отзывов пока нет. Вы можете стать первым, кто поделится впечатлением об этом товаре.",
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant
            )
            else -> {
                reviews.forEach { review ->
                    Column(modifier = Modifier.fillMaxWidth().padding(vertical = 12.dp)) {
                        Row(
                            modifier = Modifier.fillMaxWidth(),
                            horizontalArrangement = Arrangement.SpaceBetween,
                            verticalAlignment = Alignment.CenterVertically
                        ) {
                            Text(
                                text = review.authorName,
                                style = MaterialTheme.typography.titleMedium,
                                color = ProductDark
                            )
                            Text(
                                text = review.createdAt?.substringBefore(" ")?.takeIf { it.isNotBlank() } ?: "",
                                style = MaterialTheme.typography.bodySmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant
                            )
                        }
                        Spacer(Modifier.height(6.dp))
                        Row {
                            repeat(5) { index ->
                                Icon(
                                    imageVector = if (index < review.rating) Icons.Default.Star else Icons.Default.StarBorder,
                                    contentDescription = null,
                                    tint = if (index < review.rating) Color(0xFFFFB300) else Color.LightGray,
                                    modifier = Modifier.size(20.dp)
                                )
                            }
                        }
                        if (!review.reviewText.isNullOrBlank()) {
                            Spacer(Modifier.height(8.dp))
                            Text(text = review.reviewText, style = MaterialTheme.typography.bodyMedium)
                        }
                    }
                    Divider()
                }
                if ((summary?.reviewsCount ?: 0) > reviews.size) {
                    Spacer(Modifier.height(8.dp))
                    Text(
                        text = "Показаны первые ${reviews.size} отзывов из ${summary?.reviewsCount}.",
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant
                    )
                }
            }
        }

        Spacer(Modifier.height(20.dp))
        Button(
            onClick = onLeaveReviewClick,
            modifier = Modifier.fillMaxWidth().height(52.dp),
            shape = RoundedCornerShape(14.dp),
            colors = androidx.compose.material3.ButtonDefaults.buttonColors(
                containerColor = ProductRed,
                contentColor = Color.White
            )
        ) {
            Text("Оставить отзыв")
        }
    }
}
// ===== КОНЕЦ БЛОКА: Общий блок отзывов =====


// ===== НАЧАЛО БЛОКА: Вкладка Описание =====
@Composable
private fun ProductDescriptionTab(product: Product) {
    val description = product.description
        ?.takeIf { it.isNotBlank() }

    Text(
        text = description ?: "Описание товара пока не добавлено.",
        style = MaterialTheme.typography.bodyLarge,
        color = if (description == null) {
            MaterialTheme.colorScheme.onSurfaceVariant
        } else {
            MaterialTheme.colorScheme.onSurface
        }
    )
}
// ===== КОНЕЦ БЛОКА: Вкладка Описание =====


// ===== НАЧАЛО БЛОКА: Вкладка Характеристики =====
@Composable
private fun ProductCharacteristicsTab(product: Product) {

    // ===== НАЧАЛО БЛОКА: Основные характеристики =====
    if (ProductDetailFieldSettings.showManufacturer) {
        ProductInfo("Производитель", product.manufacturer)
    }

    if (ProductDetailFieldSettings.showCategory) {
        ProductInfo("Категория", product.categoryName)
    }
    // ===== КОНЕЦ БЛОКА: Основные характеристики =====

    // ===== НАЧАЛО БЛОКА: Дополнительные характеристики =====
    if (ProductDetailFieldSettings.showBarcode) {
        ProductInfo("Штрихкод", product.barcode)
    }

    if (ProductDetailFieldSettings.showArticle) {
        ProductInfo("Артикул", product.article)
    }

    if (ProductDetailFieldSettings.showUnit) {
        ProductInfo("Единица измерения", product.unit)
    }

    if (ProductDetailFieldSettings.showBranch) {
        ProductInfo("Филиал", product.branchId?.toString())
    }

    if (ProductDetailFieldSettings.showImportedOn) {
        ProductInfo("Дата обновления", product.importedOn)
    }
    // ===== КОНЕЦ БЛОКА: Дополнительные характеристики =====

    // ===== НАЧАЛО БЛОКА: Проверка наличия характеристик =====
    val hasManufacturer =
        ProductDetailFieldSettings.showManufacturer &&
                !product.manufacturer.isNullOrBlank()

    val hasCategory =
        ProductDetailFieldSettings.showCategory &&
                !product.categoryName.isNullOrBlank()

    val hasExtraFields =
        (ProductDetailFieldSettings.showBarcode && !product.barcode.isNullOrBlank()) ||
                (ProductDetailFieldSettings.showArticle && !product.article.isNullOrBlank()) ||
                (ProductDetailFieldSettings.showUnit && !product.unit.isNullOrBlank()) ||
                (ProductDetailFieldSettings.showBranch && product.branchId != null) ||
                (ProductDetailFieldSettings.showImportedOn && !product.importedOn.isNullOrBlank())

    if (!hasManufacturer && !hasCategory && !hasExtraFields) {
        Text(
            text = "Характеристики товара пока не указаны.",
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant
        )
    }
    // ===== КОНЕЦ БЛОКА: Проверка наличия характеристик =====
}
// ===== КОНЕЦ БЛОКА: Вкладка Характеристики =====


// ===== НАЧАЛО БЛОКА: Загрузка фотографии товара =====
@Composable
private fun ProductDetailImage(
    imageUrl: String?,
    productName: String
) {
    val bitmap by produceState<android.graphics.Bitmap?>(
        initialValue = null,
        key1 = imageUrl
    ) {
        value = withContext(Dispatchers.IO) {
            if (imageUrl.isNullOrBlank()) {
                null
            } else {
                var connection: HttpURLConnection? = null

                try {
                    val url = URL(imageUrl)

                    if (url.protocol != "https") {
                        null
                    } else {
                        connection = url.openConnection() as HttpURLConnection
                        connection.connectTimeout = 8000
                        connection.readTimeout = 8000
                        connection.instanceFollowRedirects = false

                        if (connection.responseCode == HttpURLConnection.HTTP_OK) {
                            connection.inputStream.use { input ->
                                BitmapFactory.decodeStream(input)
                            }
                        } else {
                            null
                        }
                    }
                } catch (_: Exception) {
                    null
                } finally {
                    connection?.disconnect()
                }
            }
        }
    }

    Box(
        modifier = Modifier
            .fillMaxWidth()
            .height(300.dp)
            .background(
                ProductLightGray,
                RoundedCornerShape(18.dp)
            ),
        contentAlignment = Alignment.Center
    ) {
        if (bitmap != null) {
            Image(
                bitmap = bitmap!!.asImageBitmap(),
                contentDescription = productName,
                contentScale = ContentScale.Fit,
                modifier = Modifier
                    .fillMaxSize()
                    .padding(12.dp)
            )
        } else {
            Icon(
                imageVector = Icons.Default.ImageNotSupported,
                contentDescription = "Фото отсутствует",
                tint = Color.Gray,
                modifier = Modifier.size(64.dp)
            )
        }
    }
}
// ===== КОНЕЦ БЛОКА: Загрузка фотографии товара =====


// ===== НАЧАЛО БЛОКА: Универсальная строка характеристики =====
@Composable
private fun ProductInfo(
    label: String,
    value: String?
) {
    if (!value.isNullOrBlank()) {
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .padding(vertical = 9.dp),
            horizontalArrangement = Arrangement.SpaceBetween
        ) {
            Text(
                text = label,
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant
            )

            Text(
                text = value,
                style = MaterialTheme.typography.bodyMedium
            )
        }
        Divider()
    }
}
// ===== КОНЕЦ БЛОКА: Универсальная строка характеристики =====

