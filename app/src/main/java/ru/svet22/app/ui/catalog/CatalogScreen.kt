package ru.svet22.app.ui.catalog

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Search
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import androidx.lifecycle.viewmodel.compose.viewModel
import ru.svet22.app.data.model.Product

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun CatalogScreen(
    onProductClick: (Product) -> Unit,
    vm: CatalogViewModel = viewModel()
) {
    var branchMenuExpanded by remember { mutableStateOf(false) }
    val visibleProducts = vm.products.filter {
        it.name.contains(vm.search, ignoreCase = true)
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = {
                    Column {
                        Text("Светофор")
                        Text(
                            text = vm.selectedBranch?.name ?: "Выбор магазина",
                            style = MaterialTheme.typography.labelMedium
                        )
                    }
                },
                actions = {
                    Button(onClick = { branchMenuExpanded = true }) {
                        Text("Магазин")
                    }
                    DropdownMenu(
                        expanded = branchMenuExpanded,
                        onDismissRequest = { branchMenuExpanded = false }
                    ) {
                        vm.branches.forEach { branch ->
                            DropdownMenuItem(
                                text = { Text(branch.name) },
                                onClick = {
                                    branchMenuExpanded = false
                                    vm.selectBranch(branch)
                                }
                            )
                        }
                    }
                }
            )
        }
    ) { padding ->
        Column(
            modifier = Modifier.fillMaxSize()
                .padding(padding)
                .padding(horizontal = 16.dp)
        ) {
            Spacer(Modifier.height(12.dp))
            OutlinedTextField(
                value = vm.search,
                onValueChange = vm::updateSearch,
                modifier = Modifier.fillMaxWidth(),
                singleLine = true,
                label = { Text("Поиск товаров") },
                leadingIcon = {
                    Icon(Icons.Default.Search, contentDescription = null)
                }
            )
            Spacer(Modifier.height(12.dp))
            if (vm.loading && vm.products.isEmpty()) {
                CircularProgressIndicator(
                    modifier = Modifier.align(Alignment.CenterHorizontally)
                )
            } else if (vm.error != null) {
                Text(vm.error!!, color = MaterialTheme.colorScheme.error)
                Spacer(Modifier.height(8.dp))
                Button(onClick = vm::retry) { Text("Повторить") }
            } else if (visibleProducts.isEmpty()) {
                Text(
                    if (vm.products.isEmpty()) "В этом магазине пока нет товаров."
                    else "По вашему запросу ничего не найдено."
                )
            } else {
                Text(
                    text = "Товары магазина",
                    style = MaterialTheme.typography.titleMedium,
                    modifier = Modifier.padding(vertical = 8.dp)
                )
                LazyColumn(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    items(visibleProducts, key = { it.id }) { product ->
                        ProductRow(product = product, onClick = { onProductClick(product) })
                    }
                }
            }
        }
    }
}
