
package ru.svet22.app.ui.home

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp

// ===== НАЧАЛО БЛОКА: Цвета главной страницы =====
private val SvetRed = Color(0xFFE53935)
private val SvetYellow = Color(0xFFFFD600)
private val SvetDark = Color(0xFF252525)
private val SvetGray = Color(0xFF757575)
private val SvetBackground = Color(0xFFF7F7F7)
// ===== КОНЕЦ БЛОКА: Цвета главной страницы =====

// ===== НАЧАЛО БЛОКА: Главная страница приложения =====
@Composable
fun HomeScreen(
    onOpenCatalog: () -> Unit,
    onOpenOverview: () -> Unit
) {
    Column(
        modifier = Modifier
            .fillMaxSize()
            .background(SvetBackground)
            .verticalScroll(rememberScrollState())
            .padding(horizontal = 18.dp, vertical = 16.dp)
    ) {
        // ===== НАЧАЛО БЛОКА: Верхняя панель =====
        Row(
            modifier = Modifier.fillMaxWidth(),
            verticalAlignment = Alignment.CenterVertically
        ) {
            Column(modifier = Modifier.weight(1f)) {
                Text(
                    text = "СВЕТОФОР",
                    color = SvetRed,
                    fontSize = 25.sp,
                    fontWeight = FontWeight.ExtraBold
                )
                Text(
                    text = "Каталог товаров",
                    color = SvetGray,
                    style = MaterialTheme.typography.bodyMedium
                )
            }

            Surface(
                color = SvetYellow,
                shape = RoundedCornerShape(14.dp)
            ) {
                Text(
                    text = "БИЙСК",
                    modifier = Modifier.padding(
                        horizontal = 14.dp,
                        vertical = 9.dp
                    ),
                    color = SvetDark,
                    fontWeight = FontWeight.Bold,
                    fontSize = 13.sp
                )
            }
        }
        // ===== КОНЕЦ БЛОКА: Верхняя панель =====

        Spacer(modifier = Modifier.height(24.dp))

        // ===== НАЧАЛО БЛОКА: Приветственный баннер =====
        Card(
            modifier = Modifier.fillMaxWidth(),
            shape = RoundedCornerShape(24.dp),
            colors = CardDefaults.cardColors(
                containerColor = SvetRed
            )
        ) {
            Column(
                modifier = Modifier.padding(22.dp)
            ) {
                Text(
                    text = "ПОКУПКИ СТАЛИ ПРОЩЕ",
                    color = SvetYellow,
                    fontSize = 12.sp,
                    fontWeight = FontWeight.Bold
                )

                Spacer(modifier = Modifier.height(12.dp))

                Text(
                    text = "Всё нужное\nрядом с вами",
                    color = Color.White,
                    fontSize = 29.sp,
                    lineHeight = 35.sp,
                    fontWeight = FontWeight.ExtraBold
                )

                Spacer(modifier = Modifier.height(10.dp))

                Text(
                    text = "Смотрите ассортимент, цены и наличие товаров в магазинах.",
                    color = Color.White.copy(alpha = 0.92f),
                    style = MaterialTheme.typography.bodyMedium
                )

                Spacer(modifier = Modifier.height(20.dp))

                Button(
                    onClick = onOpenCatalog,
                    colors = ButtonDefaults.buttonColors(
                        containerColor = SvetYellow,
                        contentColor = SvetDark
                    ),
                    shape = RoundedCornerShape(14.dp)
                ) {
                    Text(
                        text = "Перейти в каталог",
                        fontWeight = FontWeight.Bold
                    )
                }
            }
        }
        // ===== КОНЕЦ БЛОКА: Приветственный баннер =====

        Spacer(modifier = Modifier.height(26.dp))

        // ===== НАЧАЛО БЛОКА: Заголовок раздела =====
        Text(
            text = "Что вас интересует?",
            color = SvetDark,
            fontSize = 21.sp,
            fontWeight = FontWeight.Bold
        )

        Spacer(modifier = Modifier.height(14.dp))
        // ===== КОНЕЦ БЛОКА: Заголовок раздела =====

        // ===== НАЧАЛО БЛОКА: Карточки переходов =====
        HomeActionCard(
            title = "Каталог товаров",
            description = "Ассортимент, цены и остатки в магазинах",
            accentColor = SvetYellow,
            onClick = onOpenCatalog
        )

        Spacer(modifier = Modifier.height(12.dp))

        HomeActionCard(
            title = "Обзор товаров",
            description = "Популярные товары и интересные новинки",
            accentColor = SvetRed,
            onClick = onOpenOverview
        )
        // ===== КОНЕЦ БЛОКА: Карточки переходов =====

        Spacer(modifier = Modifier.height(26.dp))

        // ===== НАЧАЛО БЛОКА: Информационный блок =====
        Card(
            modifier = Modifier.fillMaxWidth(),
            shape = RoundedCornerShape(20.dp),
            colors = CardDefaults.cardColors(
                containerColor = Color.White
            )
        ) {
            Column(
                modifier = Modifier.padding(18.dp)
            ) {
                Text(
                    text = "Выбирайте удобный магазин",
                    color = SvetDark,
                    fontWeight = FontWeight.Bold,
                    style = MaterialTheme.typography.titleMedium
                )

                Spacer(modifier = Modifier.height(7.dp))

                Text(
                    text = "Проверяйте наличие и стоимость товаров отдельно для каждого филиала.",
                    color = SvetGray,
                    style = MaterialTheme.typography.bodyMedium
                )
            }
        }
        // ===== КОНЕЦ БЛОКА: Информационный блок =====

        Spacer(modifier = Modifier.height(20.dp))

        // ===== НАЧАЛО БЛОКА: Нижняя подпись =====
        Text(
            text = "Светофор · Бийск",
            modifier = Modifier
                .fillMaxWidth()
                .padding(bottom = 12.dp),
            color = SvetGray,
            fontSize = 12.sp
        )
        // ===== КОНЕЦ БЛОКА: Нижняя подпись =====
    }
}
// ===== КОНЕЦ БЛОКА: Главная страница приложения =====

// ===== НАЧАЛО БЛОКА: Универсальная карточка перехода =====
@Composable
private fun HomeActionCard(
    title: String,
    description: String,
    accentColor: Color,
    onClick: () -> Unit
) {
    Card(
        modifier = Modifier.fillMaxWidth(),
        onClick = onClick,
        shape = RoundedCornerShape(20.dp),
        colors = CardDefaults.cardColors(
            containerColor = Color.White
        ),
        elevation = CardDefaults.cardElevation(
            defaultElevation = 2.dp
        )
    ) {
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .padding(18.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(14.dp)
        ) {
            Surface(
                color = accentColor,
                shape = RoundedCornerShape(13.dp)
            ) {
                Spacer(
                    modifier = Modifier
                        .padding(5.dp)
                        .height(30.dp)
                        .background(
                            accentColor,
                            RoundedCornerShape(8.dp)
                        )
                )
            }

            Column(modifier = Modifier.weight(1f)) {
                Text(
                    text = title,
                    color = SvetDark,
                    fontWeight = FontWeight.Bold,
                    style = MaterialTheme.typography.titleMedium
                )

                Spacer(modifier = Modifier.height(4.dp))

                Text(
                    text = description,
                    color = SvetGray,
                    style = MaterialTheme.typography.bodySmall
                )
            }

            Text(
                text = "›",
                color = SvetRed,
                fontSize = 30.sp,
                fontWeight = FontWeight.Bold
            )
        }
    }
}
// ===== КОНЕЦ БЛОКА: Универсальная карточка перехода =====
