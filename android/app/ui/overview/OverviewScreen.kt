
package ru.svet22.app.ui.overview

import androidx.compose.foundation.background
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import ru.svet22.app.ui.catalog.CatalogViewModel
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
import androidx.compose.material.icons.filled.ArrowForward
import androidx.compose.material.icons.filled.LocalOffer
import androidx.compose.material.icons.filled.NewReleases
import androidx.compose.material.icons.filled.ShoppingBasket
import androidx.compose.material.icons.filled.Storefront
import androidx.compose.material.icons.filled.Visibility
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp

// ===== НАЧАЛО БЛОКА: Цветовая палитра экрана =====
private val OverviewRed = Color(0xFFE53935)
private val OverviewYellow = Color(0xFFFFD600)
private val OverviewDark = Color(0xFF252525)
private val OverviewGray = Color(0xFF757575)
private val OverviewBackground = Color(0xFFF7F7F7)
// ===== КОНЕЦ БЛОКА: Цветовая палитра экрана =====


// ===== НАЧАЛО БЛОКА: Основной экран Обзор =====
@Composable
fun OverviewScreen(
    onOpenCatalog: () -> Unit,
    vm: CatalogViewModel
) {
    // ===== НАЧАЛО БЛОКА: Состояние выбора магазина =====
    var branchMenuExpanded by remember {
        mutableStateOf(false)
    }
    // ===== КОНЕЦ БЛОКА: Состояние выбора магазина =====
    Column(
        modifier = Modifier
            .fillMaxSize()
            .background(OverviewBackground)
            .verticalScroll(rememberScrollState())
            .padding(horizontal = 18.dp, vertical = 16.dp)
    ) {

        // ===== НАЧАЛО БЛОКА: Заголовок страницы =====
        Row(
            modifier = Modifier.fillMaxWidth(),
            verticalAlignment = Alignment.CenterVertically
        ) {
            Column(
                modifier = Modifier.weight(1f)
            ) {
                Text(
                    text = "Обзор товаров",
                    color = OverviewDark,
                    fontSize = 26.sp,
                    fontWeight = FontWeight.ExtraBold
                )

                Spacer(Modifier.height(5.dp))

                Text(
                    text = "Интересное в магазинах Светофор",
                    color = OverviewGray,
                    style = MaterialTheme.typography.bodyMedium
                )
            }

            // ===== НАЧАЛО БЛОКА: Выбор филиала по названию =====
            Box {
                Button(
                    onClick = {
                        branchMenuExpanded = true
                    },
                    enabled = vm.branches.isNotEmpty(),
                    colors = ButtonDefaults.buttonColors(
                        containerColor = OverviewYellow,
                        contentColor = OverviewDark
                    ),
                    shape = RoundedCornerShape(14.dp)
                ) {
                    Text(
                        text = vm.selectedBranch?.name ?: "Магазин",
                        maxLines = 1
                    )
                }

                DropdownMenu(
                    expanded = branchMenuExpanded,
                    onDismissRequest = {
                        branchMenuExpanded = false
                    }
                ) {
                    vm.branches.forEach { branch ->
                        DropdownMenuItem(
                            text = {
                                Text(branch.name)
                            },
                            onClick = {
                                branchMenuExpanded = false
                                vm.selectBranch(branch)
                            }
                        )
                    }
                }
            }
// ===== КОНЕЦ БЛОКА: Выбор филиала по названию =====
        }
        // ===== КОНЕЦ БЛОКА: Заголовок страницы =====

        Spacer(Modifier.height(24.dp))

        // ===== НАЧАЛО БЛОКА: Главный рекламный баннер =====
        Card(
            modifier = Modifier.fillMaxWidth(),
            shape = RoundedCornerShape(24.dp),
            colors = CardDefaults.cardColors(
                containerColor = OverviewRed
            )
        ) {
            Column(
                modifier = Modifier.padding(22.dp)
            ) {
                Text(
                    text = "ВИТРИНА ТОВАРОВ",
                    color = OverviewYellow,
                    fontSize = 12.sp,
                    fontWeight = FontWeight.Bold
                )

                Spacer(Modifier.height(12.dp))

                Text(
                    text = "Откройте\nчто-то новое!",
                    color = Color.White,
                    fontSize = 29.sp,
                    lineHeight = 35.sp,
                    fontWeight = FontWeight.ExtraBold
                )

                Spacer(Modifier.height(10.dp))

                Text(
                    text = "Знакомьтесь с ассортиментом и находите нужные товары.",
                    color = Color.White.copy(alpha = 0.92f),
                    style = MaterialTheme.typography.bodyMedium
                )

                Spacer(Modifier.height(18.dp))

                Button(
                    onClick = onOpenCatalog,
                    colors = ButtonDefaults.buttonColors(
                        containerColor = OverviewYellow,
                        contentColor = OverviewDark
                    ),
                    shape = RoundedCornerShape(14.dp)
                ) {
                    Text(
                        text = "Открыть каталог",
                        fontWeight = FontWeight.Bold
                    )

                    Spacer(Modifier.size(8.dp))

                    Icon(
                        imageVector = Icons.Default.ArrowForward,
                        contentDescription = null,
                        modifier = Modifier.size(18.dp)
                    )
                }
            }
        }
        // ===== КОНЕЦ БЛОКА: Главный рекламный баннер =====

        Spacer(Modifier.height(28.dp))

        // ===== НАЧАЛО БЛОКА: Популярные товары =====
        OverviewSectionHeader(
            title = "Популярные товары",
            subtitle = "Подборка интересных предложений"
        )

        Spacer(Modifier.height(14.dp))

        OverviewInfoCard(
            title = "Популярное",
            description = "Здесь появятся товары, которые чаще всего просматривают покупатели.",
            icon = {
                Icon(
                    imageVector = Icons.Default.ShoppingBasket,
                    contentDescription = null,
                    tint = OverviewRed,
                    modifier = Modifier.size(30.dp)
                )
            }
        )
        // ===== КОНЕЦ БЛОКА: Популярные товары =====

        Spacer(Modifier.height(28.dp))

        // ===== НАЧАЛО БЛОКА: Новинки =====
        OverviewSectionHeader(
            title = "Новинки",
            subtitle = "Новые товары в ассортименте"
        )

        Spacer(Modifier.height(14.dp))

        OverviewInfoCard(
            title = "Новые поступления",
            description = "В этом разделе будут отображаться товары, недавно добавленные в каталог.",
            icon = {
                Icon(
                    imageVector = Icons.Default.NewReleases,
                    contentDescription = null,
                    tint = OverviewRed,
                    modifier = Modifier.size(30.dp)
                )
            }
        )
        // ===== КОНЕЦ БЛОКА: Новинки =====

        Spacer(Modifier.height(28.dp))

        // ===== НАЧАЛО БЛОКА: Тематические подборки =====
        OverviewSectionHeader(
            title = "Подборки товаров",
            subtitle = "Удобный способ найти нужное"
        )

        Spacer(Modifier.height(14.dp))

        Row(
            modifier = Modifier.fillMaxWidth(),
            horizontalArrangement = Arrangement.spacedBy(12.dp)
        ) {
            OverviewCollectionCard(
                title = "Продукты",
                description = "Ассортимент",
                backgroundColor = Color(0xFFFFF2CF),
                modifier = Modifier.weight(1f),
                onClick = onOpenCatalog,
                icon = {
                    Icon(
                        imageVector = Icons.Default.ShoppingBasket,
                        contentDescription = null,
                        tint = OverviewDark,
                        modifier = Modifier.size(30.dp)
                    )
                }
            )

            OverviewCollectionCard(
                title = "Все товары",
                description = "Каталог",
                backgroundColor = Color(0xFFFFE5E5),
                modifier = Modifier.weight(1f),
                onClick = onOpenCatalog,
                icon = {
                    Icon(
                        imageVector = Icons.Default.LocalOffer,
                        contentDescription = null,
                        tint = OverviewRed,
                        modifier = Modifier.size(30.dp)
                    )
                }
            )
        }
        // ===== КОНЕЦ БЛОКА: Тематические подборки =====

        Spacer(Modifier.height(28.dp))

        // ===== НАЧАЛО БЛОКА: Информация о филиалах =====
        Card(
            modifier = Modifier.fillMaxWidth(),
            shape = RoundedCornerShape(20.dp),
            colors = CardDefaults.cardColors(
                containerColor = Color.White
            )
        ) {
            Row(
                modifier = Modifier.padding(18.dp),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.spacedBy(14.dp)
            ) {
                Surface(
                    color = OverviewYellow,
                    shape = RoundedCornerShape(14.dp)
                ) {
                    Icon(
                        imageVector = Icons.Default.Storefront,
                        contentDescription = null,
                        tint = OverviewDark,
                        modifier = Modifier.padding(12.dp)
                    )
                }

                Column(
                    modifier = Modifier.weight(1f)
                ) {
                    Text(
                        text = "Наличие в магазинах",
                        color = OverviewDark,
                        fontWeight = FontWeight.Bold,
                        style = MaterialTheme.typography.titleMedium
                    )

                    Spacer(Modifier.height(5.dp))

                    Text(
                        text = "Цены и остатки товаров смотрите в каталоге, выбрав нужный филиал.",
                        color = OverviewGray,
                        style = MaterialTheme.typography.bodySmall
                    )
                }
            }
        }
        // ===== КОНЕЦ БЛОКА: Информация о филиалах =====

        Spacer(Modifier.height(24.dp))

        // ===== НАЧАЛО БЛОКА: Нижняя кнопка каталога =====
        Button(
            onClick = onOpenCatalog,
            modifier = Modifier.fillMaxWidth(),
            shape = RoundedCornerShape(16.dp),
            colors = ButtonDefaults.buttonColors(
                containerColor = OverviewRed,
                contentColor = Color.White
            )
        ) {
            Text(
                text = "Перейти ко всем товарам",
                modifier = Modifier.padding(vertical = 5.dp),
                fontWeight = FontWeight.Bold
            )
        }
        // ===== КОНЕЦ БЛОКА: Нижняя кнопка каталога =====

        Spacer(Modifier.height(20.dp))
    }
}
// ===== КОНЕЦ БЛОКА: Основной экран Обзор =====


// ===== НАЧАЛО БЛОКА: Заголовок раздела =====
@Composable
private fun OverviewSectionHeader(
    title: String,
    subtitle: String
) {
    Column {
        Text(
            text = title,
            color = OverviewDark,
            fontSize = 21.sp,
            fontWeight = FontWeight.Bold
        )

        Spacer(Modifier.height(5.dp))

        Text(
            text = subtitle,
            color = OverviewGray,
            style = MaterialTheme.typography.bodyMedium
        )
    }
}
// ===== КОНЕЦ БЛОКА: Заголовок раздела =====


// ===== НАЧАЛО БЛОКА: Информационная карточка =====
@Composable
private fun OverviewInfoCard(
    title: String,
    description: String,
    icon: @Composable () -> Unit
) {
    Card(
        modifier = Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(20.dp),
        colors = CardDefaults.cardColors(
            containerColor = Color.White
        ),
        elevation = CardDefaults.cardElevation(
            defaultElevation = 2.dp
        )
    ) {
        Row(
            modifier = Modifier.padding(18.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(14.dp)
        ) {
            Box(
                modifier = Modifier
                    .size(58.dp)
                    .background(
                        Color(0xFFFFF2CF),
                        RoundedCornerShape(16.dp)
                    ),
                contentAlignment = Alignment.Center
            ) {
                icon()
            }

            Column(
                modifier = Modifier.weight(1f)
            ) {
                Text(
                    text = title,
                    color = OverviewDark,
                    fontWeight = FontWeight.Bold,
                    style = MaterialTheme.typography.titleMedium
                )

                Spacer(Modifier.height(5.dp))

                Text(
                    text = description,
                    color = OverviewGray,
                    style = MaterialTheme.typography.bodySmall
                )
            }
        }
    }
}
// ===== КОНЕЦ БЛОКА: Информационная карточка =====


// ===== НАЧАЛО БЛОКА: Карточка подборки =====
@Composable
private fun OverviewCollectionCard(
    title: String,
    description: String,
    backgroundColor: Color,
    modifier: Modifier = Modifier,
    onClick: () -> Unit,
    icon: @Composable () -> Unit
) {
    Card(
        onClick = onClick,
        modifier = modifier,
        shape = RoundedCornerShape(20.dp),
        colors = CardDefaults.cardColors(
            containerColor = backgroundColor
        )
    ) {
        Column(
            modifier = Modifier
                .fillMaxWidth()
                .padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp)
        ) {
            icon()

            Text(
                text = title,
                color = OverviewDark,
                fontWeight = FontWeight.Bold,
                style = MaterialTheme.typography.titleMedium
            )

            Text(
                text = description,
                color = OverviewGray,
                style = MaterialTheme.typography.bodySmall
            )
        }
    }
}
// ===== КОНЕЦ БЛОКА: Карточка подборки =====
