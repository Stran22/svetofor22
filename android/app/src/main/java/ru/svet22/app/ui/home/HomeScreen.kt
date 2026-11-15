package ru.svet22.app.ui.home

import android.graphics.BitmapFactory
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
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
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ArrowForward
import androidx.compose.material.icons.filled.KeyboardArrowDown
import androidx.compose.material.icons.filled.Search
import androidx.compose.material.icons.filled.Storefront
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
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
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import ru.svet22.app.data.model.Product
import ru.svet22.app.data.model.ContestSummary
import ru.svet22.app.data.repository.ContestRepository
import ru.svet22.app.ui.contest.ContestImage
import ru.svet22.app.ui.catalog.CatalogViewModel
import java.net.URL

private val Red = Color(0xFFE53935)
private val Yellow = Color(0xFFFFD600)
private val Dark = Color(0xFF222222)
private val Muted = Color(0xFF757575)
private val Background = Color(0xFFF7F7F7)

@Composable
fun HomeScreen(
    vm: CatalogViewModel,
    onOpenCatalog: () -> Unit,
    onOpenOverview: () -> Unit,
    onOpenProduct: (Product) -> Unit,
    onOpenContest: (Int) -> Unit
) {
    val contests by produceState<List<ContestSummary>>(initialValue = emptyList()) {
        value = runCatching { ContestRepository().active() }.getOrDefault(emptyList())
    }
    var branchMenuExpanded by remember { mutableStateOf(false) }
    var showAllFeatured by remember { mutableStateOf(false) }
    val featured = remember(vm.products) { vm.products.filter { it.price?.toDoubleOrNull() != null }.take(12) }
    val categories = remember(vm.categories) { vm.categories.filter { it.parentId == null }.take(12) }

    Column(
        modifier = Modifier.fillMaxSize().background(Background).verticalScroll(rememberScrollState())
    ) {
        Row(
            modifier = Modifier.fillMaxWidth().background(Color.White).padding(start = 18.dp, end = 12.dp, top = 18.dp, bottom = 14.dp),
            verticalAlignment = Alignment.CenterVertically
        ) {
            Box(modifier = Modifier.weight(1f)) {
                Row(
                    modifier = Modifier.fillMaxWidth().clickable(enabled = vm.branches.isNotEmpty()) { branchMenuExpanded = true },
                    verticalAlignment = Alignment.CenterVertically
                ) {
                    Icon(Icons.Default.Storefront, contentDescription = null, tint = Red, modifier = Modifier.size(25.dp))
                    Spacer(Modifier.width(9.dp))
                    Column(modifier = Modifier.weight(1f)) {
                        Text("Ваш магазин", color = Muted, fontSize = 12.sp)
                        Text(
                            vm.selectedBranch?.name ?: "Выберите филиал",
                            color = Dark, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis
                        )
                        vm.selectedBranch?.address?.let {
                            Text(it, color = Muted, fontSize = 11.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                        }
                    }
                    Icon(Icons.Default.KeyboardArrowDown, contentDescription = "Выбрать филиал", tint = Red)
                }
                DropdownMenu(expanded = branchMenuExpanded, onDismissRequest = { branchMenuExpanded = false }) {
                    vm.branches.forEach { branch ->
                        DropdownMenuItem(
                            text = {
                                Column {
                                    Text(branch.name, fontWeight = FontWeight.SemiBold)
                                    branch.address?.let { Text(it, fontSize = 12.sp, color = Muted) }
                                }
                            },
                            onClick = { branchMenuExpanded = false; vm.selectBranch(branch) }
                        )
                    }
                }
            }
            Spacer(Modifier.width(10.dp))
            Surface(color = Background, shape = RoundedCornerShape(16.dp)) {
                IconButton(onClick = onOpenCatalog) {
                    Icon(Icons.Default.Search, contentDescription = "Поиск товаров", tint = Dark)
                }
            }
        }

        Column(modifier = Modifier.padding(top = 18.dp)) {
            Row(
                modifier = Modifier.fillMaxWidth().padding(horizontal = 18.dp),
                verticalAlignment = Alignment.CenterVertically
            ) {
                Text("СВЕТОФОР", color = Red, fontWeight = FontWeight.ExtraBold, fontSize = 29.sp)
                Spacer(Modifier.weight(1f))
                Surface(color = Yellow, shape = RoundedCornerShape(12.dp)) {
                    Text("БИЙСК", modifier = Modifier.padding(horizontal = 12.dp, vertical = 7.dp), fontSize = 12.sp, fontWeight = FontWeight.ExtraBold)
                }
            }
            Spacer(Modifier.height(14.dp))
            Card(
                onClick = onOpenCatalog,
                modifier = Modifier.fillMaxWidth().padding(horizontal = 18.dp),
                shape = RoundedCornerShape(24.dp),
                colors = CardDefaults.cardColors(containerColor = Red)
            ) {
                Column(modifier = Modifier.padding(22.dp)) {
                    Surface(color = Yellow, shape = RoundedCornerShape(8.dp)) {
                        Text("ЦЕНЫ И НАЛИЧИЕ", modifier = Modifier.padding(horizontal = 10.dp, vertical = 5.dp), color = Dark, fontSize = 11.sp, fontWeight = FontWeight.ExtraBold)
                    }
                    Spacer(Modifier.height(14.dp))
                    Text("Всё нужное\nрядом с вами", color = Color.White, fontSize = 30.sp, lineHeight = 35.sp, fontWeight = FontWeight.ExtraBold)
                    Spacer(Modifier.height(10.dp))
                    Text("Выбирайте товары и проверяйте остатки в своём магазине", color = Color.White.copy(alpha = 0.95f), fontSize = 14.sp)
                    Spacer(Modifier.height(20.dp))
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Surface(color = Yellow, shape = RoundedCornerShape(13.dp)) {
                            Row(modifier = Modifier.padding(horizontal = 16.dp, vertical = 11.dp), verticalAlignment = Alignment.CenterVertically) {
                                Text("Смотреть каталог", color = Dark, fontWeight = FontWeight.Bold)
                                Spacer(Modifier.width(8.dp))
                                Icon(Icons.Default.ArrowForward, contentDescription = null, tint = Dark, modifier = Modifier.size(18.dp))
                            }
                        }
                        Spacer(Modifier.weight(1f))
                        Text("🛍️", fontSize = 47.sp)
                    }
                }
            }

            if (contests.isNotEmpty()) {
                Spacer(Modifier.height(18.dp))
                Text("Конкурсы", color = Dark, fontWeight = FontWeight.ExtraBold, fontSize = 21.sp, modifier = Modifier.padding(horizontal = 18.dp))
                Spacer(Modifier.height(10.dp))
                contests.forEach { contest ->
                    Card(
                        onClick = { onOpenContest(contest.id) },
                        modifier = Modifier.fillMaxWidth().padding(horizontal = 18.dp, vertical = 5.dp),
                        shape = RoundedCornerShape(16.dp)
                    ) {
                        Column {
                            ContestImage(contest.bannerUrl, Modifier.fillMaxWidth().height(130.dp))
                            Text(contest.title, fontWeight = FontWeight.Bold, modifier = Modifier.padding(12.dp))
                        }
                    }
                }
            }
            Spacer(Modifier.height(22.dp))
            Column(
                modifier = Modifier.fillMaxWidth().padding(horizontal = 8.dp)
                    .background(Color(0xFFF2F3F5), RoundedCornerShape(22.dp))
                    .padding(horizontal = 10.dp, vertical = 17.dp)
            ) {
                Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.fillMaxWidth()) {
                    Text("Супер выгода", color = Red, fontWeight = FontWeight.ExtraBold, fontSize = 22.sp, modifier = Modifier.weight(1f))
                    if (vm.featuredProducts.size > 6) {
                        Text(
                            if (showAllFeatured) "Свернуть" else "Смотреть все ›",
                            modifier = Modifier.clickable { showAllFeatured = !showAllFeatured },
                            color = Muted, fontSize = 12.sp
                        )
                    }
                }
                Spacer(Modifier.height(13.dp))
                val offers = if (showAllFeatured) vm.featuredProducts else vm.featuredProducts.take(6)
                if (offers.isEmpty()) {
                    Text(
                        if (vm.loadingFeatured) "Загружаем предложения…" else "Скоро здесь появятся специальные предложения",
                        color = Muted, fontSize = 13.sp, modifier = Modifier.padding(vertical = 12.dp)
                    )
                } else {
                    offers.chunked(3).forEach { rowProducts ->
                        Row(
                            modifier = Modifier.fillMaxWidth(),
                            horizontalArrangement = Arrangement.spacedBy(8.dp)
                        ) {
                            rowProducts.forEach { product ->
                                FeaturedTile(product, onClick = { onOpenProduct(product) }, modifier = Modifier.weight(1f))
                            }
                            repeat(3 - rowProducts.size) { Spacer(Modifier.weight(1f)) }
                        }
                        Spacer(Modifier.height(12.dp))
                    }
                }
            }

            Spacer(Modifier.height(27.dp))
            SectionHeading("Товары в вашем магазине", "Весь каталог", onOpenCatalog)
            Spacer(Modifier.height(5.dp))
            Text(
                vm.selectedBranch?.name ?: "Выберите филиал, чтобы увидеть цены",
                modifier = Modifier.padding(horizontal = 18.dp), color = Muted, fontSize = 12.sp
            )
            Spacer(Modifier.height(13.dp))
            if (featured.isEmpty()) {
                Card(
                    modifier = Modifier.fillMaxWidth().padding(horizontal = 18.dp),
                    colors = CardDefaults.cardColors(containerColor = Color.White),
                    shape = RoundedCornerShape(18.dp)
                ) {
                    Text(
                        if (vm.loadingProducts || vm.loadingBranches) "Загружаем товары…" else "Пока нет товаров с указанной ценой. Откройте каталог.",
                        modifier = Modifier.padding(18.dp), color = Muted
                    )
                }
            } else {
                Row(
                    modifier = Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()).padding(horizontal = 18.dp),
                    horizontalArrangement = Arrangement.spacedBy(12.dp)
                ) {
                    featured.forEach { product ->
                        ProductCard(product, onClick = { onOpenProduct(product) })
                    }
                }
            }

            Spacer(Modifier.height(27.dp))
            SectionHeading("Категории товаров", "Все категории", onOpenCatalog)
            Spacer(Modifier.height(13.dp))
            if (categories.isEmpty()) {
                Text(
                    if (vm.loadingCategories) "Загружаем категории…" else "Категории пока не добавлены",
                    modifier = Modifier.padding(horizontal = 18.dp), color = Muted
                )
            } else {
                Row(
                    modifier = Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()).padding(horizontal = 18.dp),
                    horizontalArrangement = Arrangement.spacedBy(10.dp)
                ) {
                    categories.forEach { category ->
                        Card(
                            onClick = onOpenCatalog,
                            modifier = Modifier.width(124.dp).height(108.dp),
                            shape = RoundedCornerShape(18.dp),
                            colors = CardDefaults.cardColors(containerColor = Color.White)
                        ) {
                            Column(
                                modifier = Modifier.fillMaxSize().padding(12.dp),
                                verticalArrangement = Arrangement.SpaceBetween
                            ) {
                                Text(categorySymbol(category.name), fontSize = 28.sp)
                                Text(category.name, fontWeight = FontWeight.SemiBold, fontSize = 12.sp, maxLines = 2, lineHeight = 15.sp, overflow = TextOverflow.Ellipsis)
                            }
                        }
                    }
                }
            }

            Spacer(Modifier.height(26.dp))
            Card(
                onClick = onOpenOverview,
                modifier = Modifier.fillMaxWidth().padding(horizontal = 18.dp),
                shape = RoundedCornerShape(20.dp),
                colors = CardDefaults.cardColors(containerColor = Yellow)
            ) {
                Row(modifier = Modifier.padding(19.dp), verticalAlignment = Alignment.CenterVertically) {
                    Column(modifier = Modifier.weight(1f)) {
                        Text("Больше интересного", fontWeight = FontWeight.ExtraBold, fontSize = 19.sp, color = Dark)
                        Spacer(Modifier.height(5.dp))
                        Text("Посмотрите обзор ассортимента", color = Dark, fontSize = 13.sp)
                    }
                    Icon(Icons.Default.ArrowForward, contentDescription = null, tint = Dark)
                }
            }
            Spacer(Modifier.height(28.dp))
        }
    }
}

@Composable
private fun SectionHeading(title: String, action: String, onClick: () -> Unit) {
    Row(
        modifier = Modifier.fillMaxWidth().padding(horizontal = 18.dp),
        verticalAlignment = Alignment.CenterVertically
    ) {
        Text(title, modifier = Modifier.weight(1f), color = Dark, fontWeight = FontWeight.ExtraBold, fontSize = 20.sp)
        Text(action, modifier = Modifier.clickable(onClick = onClick), color = Red, fontWeight = FontWeight.SemiBold, fontSize = 12.sp)
        Spacer(Modifier.width(3.dp))
        Icon(Icons.Default.ArrowForward, contentDescription = null, tint = Red, modifier = Modifier.size(16.dp).clickable(onClick = onClick))
    }
}

@Composable
private fun ProductCard(product: Product, onClick: () -> Unit) {
    Card(
        onClick = onClick,
        modifier = Modifier.width(168.dp),
        shape = RoundedCornerShape(19.dp),
        colors = CardDefaults.cardColors(containerColor = Color.White)
    ) {
        Column(modifier = Modifier.padding(12.dp)) {
            Box(
                modifier = Modifier.fillMaxWidth().height(128.dp).background(Background, RoundedCornerShape(14.dp)),
                contentAlignment = Alignment.Center
            ) {
                ProductPhoto(product.imageUrl)
            }
            Spacer(Modifier.height(10.dp))
            Text(product.name, fontSize = 13.sp, lineHeight = 17.sp, fontWeight = FontWeight.SemiBold, minLines = 2, maxLines = 2, overflow = TextOverflow.Ellipsis)
            Spacer(Modifier.height(9.dp))
            Surface(color = Yellow, shape = RoundedCornerShape(12.dp), modifier = Modifier.fillMaxWidth()) {
                Text(
                    "${product.price} ₽",
                    modifier = Modifier.padding(horizontal = 10.dp, vertical = 9.dp),
                    color = Dark, fontWeight = FontWeight.ExtraBold, fontSize = 19.sp
                )
            }
            Spacer(Modifier.height(7.dp))
            Text(
                product.quantity?.let { "Остаток: $it" } ?: "Наличие уточняется",
                color = Muted, fontSize = 11.sp, maxLines = 1, overflow = TextOverflow.Ellipsis
            )
        }
    }
}

@Composable
private fun ProductPhoto(url: String?) {
    val bitmap by produceState<android.graphics.Bitmap?>(initialValue = null, key1 = url) {
        value = if (url.isNullOrBlank()) null else withContext(Dispatchers.IO) {
            runCatching {
                val connection = URL(URL("https://baza.altaj-otdyh.ru/"), url.trim()).openConnection()
                connection.connectTimeout = 8000
                connection.readTimeout = 8000
                connection.getInputStream().use { BitmapFactory.decodeStream(it) }
            }.getOrNull()
        }
    }
    if (bitmap == null) {
        Text("🛒", fontSize = 43.sp)
    } else {
        Image(bitmap = bitmap!!.asImageBitmap(), contentDescription = null, contentScale = ContentScale.Fit, modifier = Modifier.fillMaxSize().padding(8.dp))
    }
}

private fun categorySymbol(name: String): String {
    val n = name.lowercase()
    return when {
        "молоч" in n || "сыр" in n -> "🥛"
        "мяс" in n || "колбас" in n -> "🥩"
        "хлеб" in n -> "🍞"
        "овощ" in n || "фрукт" in n -> "🍎"
        "напит" in n || "вода" in n -> "🧃"
        "кондитер" in n || "слад" in n -> "🍫"
        "чай" in n || "кофе" in n -> "☕"
        "быт" in n || "хими" in n -> "🧴"
        else -> "🛍️"
    }
}

@Composable
private fun FeaturedTile(product: Product, onClick: () -> Unit, modifier: Modifier = Modifier) {
    Column(modifier = modifier.clickable(onClick = onClick)) {
        Box(
            modifier = Modifier.fillMaxWidth().height(104.dp)
                .background(Color.White, RoundedCornerShape(16.dp)),
            contentAlignment = Alignment.Center
        ) {
            ProductPhoto(product.imageUrl)
        }
        Spacer(Modifier.height(6.dp))
        Text(
            product.name, fontSize = 11.sp, lineHeight = 13.sp,
            color = Dark, minLines = 2, maxLines = 2,
            overflow = TextOverflow.Ellipsis
        )
        Spacer(Modifier.height(6.dp))
        Surface(
            modifier = Modifier.fillMaxWidth(),
            color = Yellow, shape = RoundedCornerShape(12.dp)
        ) {
            Text(
                (product.price ?: "—") + " ₽",
                modifier = Modifier.padding(horizontal = 4.dp, vertical = 9.dp),
                fontSize = 17.sp, fontWeight = FontWeight.ExtraBold,
                maxLines = 1, textAlign = androidx.compose.ui.text.style.TextAlign.Center,
                color = Dark
            )
        }
    }
}
