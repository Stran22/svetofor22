package ru.svet22.app.ui.catalog

import android.graphics.BitmapFactory
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ArrowBack
import androidx.compose.material.icons.filled.KeyboardArrowDown
import androidx.compose.material.icons.filled.Search
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.produceState
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.sp
import androidx.compose.ui.unit.dp
import androidx.lifecycle.viewmodel.compose.viewModel
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import ru.svet22.app.data.model.Category
import ru.svet22.app.data.model.Product
import java.net.URL

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun CatalogScreen(
    onProductClick: (Product) -> Unit,
    vm: CatalogViewModel = viewModel()
) {
    var branchMenuExpanded by remember { mutableStateOf(false) }
    var searchExpanded by remember { mutableStateOf(false) }
    val selected = if (vm.globalSearchMode) null else vm.selectedCategory
    val query = vm.search.trim()
    val visibleProducts = vm.products.filter { product ->
        query.isEmpty() || listOfNotNull(product.name, product.barcode, product.article)
            .any { it.contains(query, ignoreCase = true) }
    }
    val topLevelCategories = vm.categories.filter { it.parentId == null }

    Scaffold(
        topBar = {
            TopAppBar(
                title = {
                    Row(
                        modifier = Modifier.fillMaxWidth()
                            .clickable(enabled = vm.branches.isNotEmpty()) {
                                branchMenuExpanded = true
                            },
                        verticalAlignment = Alignment.CenterVertically
                    ) {
                        Column(modifier = Modifier.weight(1f)) {
                            SvetoforWordmark()
                            Text(
                                text = vm.selectedBranch?.name ?: "Выберите магазин",
                                style = MaterialTheme.typography.labelMedium,
                                maxLines = 1,
                                overflow = TextOverflow.Ellipsis
                            )
                            Text(
                                text = vm.selectedBranch?.address ?: "Нажмите для выбора филиала",
                                style = MaterialTheme.typography.labelSmall,
                                maxLines = 1,
                                overflow = TextOverflow.Ellipsis
                            )
                        }
                        Icon(Icons.Default.KeyboardArrowDown, contentDescription = "Выбрать филиал")
                        DropdownMenu(
                            expanded = branchMenuExpanded,
                            onDismissRequest = { branchMenuExpanded = false }
                        ) {
                            vm.branches.forEach { branch ->
                                DropdownMenuItem(
                                    text = {
                                        Column {
                                            Text(branch.name)
                                            branch.address?.let {
                                                Text(it, style = MaterialTheme.typography.bodySmall)
                                            }
                                        }
                                    },
                                    onClick = {
                                        branchMenuExpanded = false
                                        vm.selectBranch(branch)
                                    }
                                )
                            }
                        }
                    }
                },
                actions = {
                    IconButton(onClick = {
                        searchExpanded = !searchExpanded
                        vm.changeGlobalSearchMode(searchExpanded)
                    }) {
                        Icon(Icons.Default.Search, contentDescription = "Поиск")
                    }
                }
            )
        }
    ) { padding ->
        Column(Modifier.fillMaxSize().padding(padding)) {
            if (searchExpanded || selected != null) {
                OutlinedTextField(
                    value = vm.search,
                    onValueChange = vm::updateSearch,
                    modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp),
                    singleLine = true,
                    label = { Text(if (vm.globalSearchMode) "Название, штрихкод или артикул" else "Поиск в категории") }
                )
            }
            if (selected != null || vm.globalSearchMode) {
                Row(
                    verticalAlignment = Alignment.CenterVertically,
                    modifier = Modifier.fillMaxWidth().padding(horizontal = 8.dp)
                ) {
                    IconButton(onClick = {
                        if (vm.globalSearchMode) {
                            searchExpanded = false
                            vm.changeGlobalSearchMode(false)
                            vm.clearCategory()
                        } else {
                            vm.clearCategory()
                        }
                    }) {
                        Icon(Icons.Default.ArrowBack, contentDescription = "Вернуться в каталог")
                    }
                    Text(selected?.name ?: "Результаты поиска", style = MaterialTheme.typography.titleLarge)
                }
            }
            val showError = vm.branchesError ?: vm.categoriesError ?:
                (if (selected != null || searchExpanded) vm.productsError else null)
            if (showError != null) {
                Column(Modifier.padding(16.dp)) {
                    Text(showError, color = MaterialTheme.colorScheme.error)
                    Spacer(Modifier.height(8.dp))
                    Button(onClick = vm::retry) { Text("Повторить") }
                }
            } else if (selected == null && !vm.globalSearchMode) {
                if (vm.loadingCategories) {
                    Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                        CircularProgressIndicator()
                    }
                } else if (topLevelCategories.isEmpty()) {
                    Text("Разделы каталога пока не добавлены.", modifier = Modifier.padding(16.dp))
                } else {
                    LazyVerticalGrid(
                        columns = GridCells.Fixed(2),
                        modifier = Modifier.fillMaxSize().padding(horizontal = 12.dp),
                        horizontalArrangement = Arrangement.spacedBy(12.dp),
                        verticalArrangement = Arrangement.spacedBy(12.dp),
                        contentPadding = androidx.compose.foundation.layout.PaddingValues(vertical = 12.dp)
                    ) {
                        items(topLevelCategories, key = { it.id }) { category ->
                            CategoryTile(category = category, onClick = {
                                vm.selectCategory(category)
                            })
                        }
                    }
                }
            } else if (vm.loadingProducts && vm.products.isEmpty()) {
                Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator()
                }
            } else if (visibleProducts.isEmpty()) {
                Text(
                    if (query.isNotEmpty()) "Товары не найдены"
                    else "В этом магазине пока нет товаров.",
                    modifier = Modifier.padding(16.dp)
                )
            } else {
                LazyVerticalGrid(
                    columns = GridCells.Fixed(2),
                    modifier = Modifier.fillMaxSize().padding(horizontal = 12.dp),
                    horizontalArrangement = Arrangement.spacedBy(12.dp),
                    verticalArrangement = Arrangement.spacedBy(12.dp),
                    contentPadding = androidx.compose.foundation.layout.PaddingValues(vertical = 12.dp)
                ) {
                    items(visibleProducts, key = { it.id }) { product ->
                        ProductGridTile(product = product, onClick = { onProductClick(product) })
                    }
                }
            }
        }
    }
}

@Composable
private fun CategoryTile(category: Category, onClick: () -> Unit) {
    Card(
        onClick = onClick,
        shape = RoundedCornerShape(18.dp),
        modifier = Modifier.fillMaxWidth().aspectRatio(0.88f)
    ) {
        Box(Modifier.fillMaxSize().background(Color(0xFFF3F3F3))) {
            Column(Modifier.fillMaxSize().padding(12.dp)) {
                Text(
                    category.name,
                    style = MaterialTheme.typography.titleMedium,
                    fontWeight = FontWeight.Bold,
                    maxLines = 3,
                    overflow = TextOverflow.Ellipsis
                )
                Spacer(Modifier.weight(1f))
                CategoryPhoto(
                    url = category.imageUrl,
                    categoryName = category.name,
                    modifier = Modifier.fillMaxWidth().height(100.dp)
                )
            }
        }
    }
}

@Composable
private fun CategoryPhoto(url: String?, categoryName: String, modifier: Modifier = Modifier) {
    val bitmap by produceState<android.graphics.Bitmap?>(initialValue = null, key1 = url) {
        value = if (url.isNullOrBlank()) null else withContext(Dispatchers.IO) {
            runCatching {
                val connection = URL(url).openConnection()
                connection.connectTimeout = 10000
                connection.readTimeout = 10000
                connection.getInputStream().use { BitmapFactory.decodeStream(it) }
            }.getOrNull()
        }
    }
    Box(modifier, contentAlignment = Alignment.Center) {
        if (bitmap != null) {
            Image(
                bitmap = bitmap!!.asImageBitmap(),
                contentDescription = null,
                contentScale = ContentScale.Fit,
                modifier = Modifier.fillMaxSize()
            )
        } else {
            CategoryIllustration(categoryName)
        }
    }
}

@Composable
private fun SvetoforWordmark() {
    Text(
        text = "СВЕТОФОР",
        color = Color(0xFFE53935),
        fontWeight = FontWeight.ExtraBold,
        fontSize = 25.sp,
        maxLines = 1
    )
}

@Composable
private fun CategoryIllustration(categoryName: String) {
    val name = categoryName.lowercase()
    val symbols = when {
        "молоч" in name || "сыр" in name -> listOf("🥛", "🧀", "🧈")
        "сахар" in name -> listOf("🍬", "🧊", "🍭")
        "хлеб" in name || "булоч" in name -> listOf("🍞", "🥐", "🥖")
        "овощ" in name || "фрукт" in name -> listOf("🍎", "🥕", "🍌")
        "заморож" in name -> listOf("🧊", "🍦", "🥟")
        "кондитер" in name || "слад" in name -> listOf("🍫", "🍪", "🧁")
        "снэк" in name || "снек" in name -> listOf("🍿", "🥨", "🥜")
        "напит" in name || "вода" in name -> listOf("🧃", "🥤", "💧")
        "мяс" in name || "колбас" in name -> listOf("🥩", "🍗", "🌭")
        "рыб" in name -> listOf("🐟", "🦐", "🥫")
        "консерв" in name -> listOf("🥫", "🫙", "🍲")
        "круп" in name || "макарон" in name -> listOf("🍚", "🍝", "🌾")
        "чай" in name || "кофе" in name -> listOf("☕", "🫖", "🍵")
        "быт" in name || "хими" in name -> listOf("🧴", "🧽", "🧼")
        else -> listOf("🛍️", "📦", "✨")
    }
    Row(
        modifier = Modifier.fillMaxWidth(),
        horizontalArrangement = Arrangement.Center,
        verticalAlignment = Alignment.Bottom
    ) {
        Text(symbols[0], fontSize = 35.sp)
        Text(symbols[1], fontSize = 44.sp)
        Text(symbols[2], fontSize = 34.sp)
    }
}

@Composable
private fun ProductGridTile(product: Product, onClick: () -> Unit) {
    Card(
        onClick = onClick,
        modifier = Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(18.dp)
    ) {
        Column(
            modifier = Modifier.fillMaxWidth()
                .background(Color.White)
                .padding(12.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp)
        ) {
            CategoryPhoto(
                url = product.imageUrl,
                categoryName = product.categoryName ?: product.name,
                modifier = Modifier.fillMaxWidth().height(120.dp)
            )
            Text(
                text = product.name,
                style = MaterialTheme.typography.bodyMedium,
                fontWeight = FontWeight.SemiBold,
                maxLines = 3,
                minLines = 2,
                overflow = TextOverflow.Ellipsis
            )
            Text(
                text = product.price?.takeIf { it.isNotBlank() }?.let { "$it ₽" }
                    ?: "Цена не указана",
                style = MaterialTheme.typography.titleMedium,
                fontWeight = FontWeight.Bold,
                color = Color(0xFFE53935)
            )
            Text(
                text = formatQuantity(product.quantity)?.let { "Остаток: $it" }
                    ?: "Наличие уточняется",
                style = MaterialTheme.typography.bodySmall,
                color = Color(0xFF757575)
            )
        }
    }
}
