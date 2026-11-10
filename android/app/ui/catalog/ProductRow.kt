
package ru.svet22.app.ui.catalog

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Divider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import ru.svet22.app.data.model.Product

@Composable
fun ProductRow(
    product: Product,
    onClick: () -> Unit
) {
    Column(
        modifier = Modifier
            .fillMaxWidth()
            .clickable(onClick = onClick)
            .padding(vertical = 10.dp)
    ) {
        Text(
            text = product.name,
            style = MaterialTheme.typography.titleSmall
        )

        product.manufacturer
            ?.takeIf { it.isNotBlank() }
            ?.let {
                Spacer(Modifier.height(3.dp))

                Text(
                    text = it,
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant
                )
            }

        Spacer(Modifier.height(8.dp))

        Row(
            modifier = Modifier.fillMaxWidth(),
            horizontalArrangement = Arrangement.SpaceBetween,
            verticalAlignment = Alignment.CenterVertically
        ) {
            Text(
                text = product.unit ?: "",
                style = MaterialTheme.typography.bodyMedium
            )

            Text(
                text = product.price?.let { "$it ₽" }
                    ?: "Цена не указана",
                style = MaterialTheme.typography.titleMedium
            )
        }

        Spacer(Modifier.height(3.dp))

        Text(
            text =formatQuantity(product.quantity)?.let {
                "Остаток: $it"
            } ?: "Наличие уточняется",
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant
        )

        Divider(
            modifier = Modifier.padding(top = 8.dp)
        )
    }
}
