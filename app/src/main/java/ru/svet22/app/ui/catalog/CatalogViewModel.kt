package ru.svet22.app.ui.catalog

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Job
import kotlinx.coroutines.launch
import ru.svet22.app.data.model.Branch
import ru.svet22.app.data.model.Product
import ru.svet22.app.data.repository.ProductRepository

class CatalogViewModel(
    private val repository: ProductRepository = ProductRepository()
) : ViewModel() {
    var branches by mutableStateOf<List<Branch>>(emptyList())
        private set
    var products by mutableStateOf<List<Product>>(emptyList())
        private set
    var selectedBranch by mutableStateOf<Branch?>(null)
        private set
    var loadingBranches by mutableStateOf(false)
        private set
    var loadingProducts by mutableStateOf(false)
        private set
    val loading: Boolean get() = loadingBranches || loadingProducts
    var branchesError by mutableStateOf<String?>(null)
        private set
    var productsError by mutableStateOf<String?>(null)
        private set
    val error: String? get() = branchesError ?: productsError
    var search by mutableStateOf("")
        private set

    private var branchesJob: Job? = null
    private var productsJob: Job? = null
    private var branchesRequestId = 0
    private var productsRequestId = 0

    init { loadBranches() }

    fun updateSearch(value: String) { search = value }

    fun selectBranch(branch: Branch) {
        if (selectedBranch?.id == branch.id) return
        selectedBranch = branch
        products = emptyList()
        productsError = null
        productsRequestId++
        productsJob?.cancel()
        productsJob = null
        loadingProducts = false
        loadProducts(branch.id)
    }

    fun loadBranches() {
        branchesRequestId++
        val requestId = branchesRequestId
        branchesJob?.cancel()
        branchesJob = viewModelScope.launch {
            loadingBranches = true
            branchesError = null
            try {
                val result = repository.getBranches()
                if (requestId != branchesRequestId) return@launch
                branches = result
                val currentBranch = selectedBranch
                val updatedBranch = currentBranch?.let { current ->
                    result.find { it.id == current.id }
                }
                val branchToSelect = updatedBranch ?: result.firstOrNull()
                if (branchToSelect != null) {
                    if (selectedBranch?.id == branchToSelect.id) {
                        selectedBranch = branchToSelect
                    } else {
                        selectBranch(branchToSelect)
                    }
                } else {
                    selectedBranch = null
                    products = emptyList()
                    productsError = null
                    productsRequestId++
                    productsJob?.cancel()
                    productsJob = null
                    loadingProducts = false
                }
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                if (requestId == branchesRequestId) {
                    branchesError = "Не удалось загрузить филиалы. Проверьте подключение к интернету."
                }
            } finally {
                if (requestId == branchesRequestId) {
                    loadingBranches = false
                    branchesJob = null
                }
            }
        }
    }

    private fun loadProducts(branchId: Int) {
        productsRequestId++
        val requestId = productsRequestId
        productsJob?.cancel()
        productsJob = viewModelScope.launch {
            loadingProducts = true
            productsError = null
            try {
                val result = repository.getProducts(branchId)
                if (requestId != productsRequestId) return@launch
                if (selectedBranch?.id != branchId) return@launch
                products = result
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                if (requestId == productsRequestId && selectedBranch?.id == branchId) {
                    productsError = "Не удалось загрузить каталог. Попробуйте ещё раз."
                }
            } finally {
                if (requestId == productsRequestId) {
                    loadingProducts = false
                    productsJob = null
                }
            }
        }
    }

    fun retry() {
        if (branches.isEmpty()) loadBranches()
        else selectedBranch?.let { loadProducts(it.id) }
    }
}
