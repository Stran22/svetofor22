package ru.svet22.app

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Search
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Divider
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.Button
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import androidx.lifecycle.viewmodel.compose.viewModel
import kotlinx.coroutines.launch
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import retrofit2.http.GET
import retrofit2.http.Query

private const val API_BASE_URL = "https://baza.altaj-otdyh.ru/api/v1/"

data class ApiEnvelope<T>(
    val success: Boolean,
    val data: T?,
    val meta: Any?
)

data class Branch(
    val id: Int,
    val code: String,
    val name: String,
    val address: String?
)

data class Product(
    val id: Int,
    val name: String,
    val manufacturer: String?,
    val unit: String?,
    val branch_id: Int?,
    val price: String?,
    val quantity: String?
)

interface SvetApi {
    @GET("branches")
    suspend fun branches(): ApiEnvelope<List<Branch>>

    @GET("products")
    suspend fun products(
        @Query("branch_id") branchId: Int,
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 50
    ): ApiEnvelope<List<Product>>
}

private object ApiClient {
    val api: SvetApi by lazy {
        Retrofit.Builder()
            .baseUrl(API_BASE_URL)
            .addConverterFactory(GsonConverterFactory.create())
            .build()
            .create(SvetApi::class.java)
    }
}

class CatalogViewModel : ViewModel() {
    var branches by mutableStateOf<List<Branch>>(emptyList())
        private set
    var products by mutableStateOf<List<Product>>(emptyList())
        private set
    var selectedBranch by mutableStateOf<Branch?>(null)
        private set
    var loading by mutableStateOf(false)
        private set
    var error by mutableStateOf<String?>(null)
        private set
    var search by mutableStateOf("")
        private set

    init {
        loadBranches()
    }

    fun updateSearch(value: String) {
        search = value
    }

    fun selectBranch(branch: Branch) {
        selectedBranch = branch
        loadProducts(branch.id)
    }

    fun loadBranches() {
        viewModelScope.launch {
            loading = true
            error = null
            try {
                val result = ApiClient.api.branches()
                branches = result.data.orEmpty()
                if (selectedBranch == null && branches.isNotEmpty()) {
                    selectBranch(branches.first())
                }
            } catch (e: Exception) {
                error = "Не удалось загрузить филиалы. Проверьте подключение к интернету."
            } finally {
                loading = false
            }
        }
    }

    private fun loadProducts(branchId: Int) {
        viewModelScope.launch {
            loading = true
            error = null
            try {
                products = ApiClient.api.products(branchId).data.orEmpty()
            } catch (e: Exception) {
                error = "Не удалось загрузить каталог. Попробуйте ещё раз."
            } finally {
                loading = false
            }
        }
    }

    fun retry() {
        val branch = selectedBranch
        if (branch == null) loadBranches() else loadProducts(branch.id)
    }
}

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContent {
            MaterialTheme {
                Surface(modifier = Modifier.fillMaxSize()) {
                    CatalogScreen()
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun CatalogScreen(vm: CatalogViewModel = viewModel()) {
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
            modifier = Modifier
                .fillMaxSize()
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
                leadingIcon = { androidx.compose.material3.Icon(Icons.Default.Search, contentDescription = null) }
            )
            Spacer(Modifier.height(12.dp))

            if (vm.loading && vm.products.isEmpty()) {
                CircularProgressIndicator(modifier = Modifier.align(Alignment.CenterHorizontally))
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
                        ProductRow(product)
                    }
                }
            }
        }
    }
}

@Composable
private fun ProductRow(product: Product) {
    Column(
        modifier = Modifier
            .fillMaxWidth()
            .clickable { /* Карточка товара подключается на следующем этапе. */ }
            .padding(vertical = 10.dp)
    ) {
        Text(product.name, style = MaterialTheme.typography.titleSmall)
        Spacer(Modifier.height(4.dp))
        Row(
            modifier = Modifier.fillMaxWidth(),
            horizontalArrangement = Arrangement.SpaceBetween,
            verticalAlignment = Alignment.CenterVertically
        ) {
            Text(product.unit ?: "")
            Text(
                text = product.price?.let { "$it ₽" } ?: "Цена не указана",
                style = MaterialTheme.typography.titleMedium
            )
        }
        Text(
            text = product.quantity?.let { "Остаток: $it" } ?: "Наличие уточняется",
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant
        )
        Divider(modifier = Modifier.padding(top = 8.dp))
    }
}
