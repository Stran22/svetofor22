
package ru.svet22.app.ui.profile

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp

@Composable
fun ProfileScreen() {
    // ===== НАЧАЛО БЛОКА: Содержимое профиля =====
    Column(
        modifier = Modifier
            .fillMaxSize()
            .padding(20.dp),
        verticalArrangement = Arrangement.Top
    ) {
        Text(
            text = "Профиль",
            style = MaterialTheme.typography.headlineMedium
        )

        Spacer(Modifier.height(12.dp))

        Text(
            text = "Войдите в приложение, чтобы пользоваться персональными возможностями."
        )

        Spacer(Modifier.height(8.dp))

        Text(
            text = "Авторизация через VK ID, Одноклассники и Яндекс ID будет добавлена отдельным этапом."
        )
    }
    // ===== КОНЕЦ БЛОКА: Содержимое профиля =====
}
