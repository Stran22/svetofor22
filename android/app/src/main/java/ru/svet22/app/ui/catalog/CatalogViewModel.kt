package ru.svet22.app.ui.catalog

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import android.app.Application
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Job
import kotlinx.coroutines.launch
import ru.svet22.app.data.model.Branch
import ru.svet22.app.data.model.Category
import ru.svet22.app.data.model.Product
import ru.svet22.app.data.repository.ProductRepository

class CatalogViewModel(
    application: Application
) : AndroidViewModel(application) {
    private val repository = ProductRepository()
    private val preferences = application.getSharedPreferences("svet22_catalog", 0)
    private val savedBranchId = preferences.getInt("selected_branch_id", -1)

    var branches by mutableStateOf<List<Branch>>(emptyList())
        private set
    var categories by mutableStateOf<List<Category>>(emptyList())
        private set
    var products by mutableStateOf<List<Product>>(emptyList())
        private set
    var selectedBranch by mutableStateOf<Branch?>(null)
        private set
    var selectedCategory by mutableStateOf<Category?>(null)
        private set
    var loadingBranches by mutableStateOf(false)
        private set
    var loadingCategories by mutableStateOf(false)
        private set
    var loadingProducts by mutableStateOf(false)
        private set
    val loading: Boolean get() = loadingBranches || loadingCategories || loadingProducts
    var branchesError by mutableStateOf<String?>(null)
        private set
    var categoriesError by mutableStateOf<String?>(null)
        private set
    var productsError by mutableStateOf<String?>(null)
        private set
    val error: String? get() = branchesError ?: categoriesError ?: productsError
    var search by mutableStateOf("")
        private set
    var globalSearchMode by mutableStateOf(false)
        private set

    private fun effectiveCategoryId(): Int? = if (globalSearchMode) null else selectedCategory?.id

    fun changeGlobalSearchMode(enabled: Boolean) {
        if (globalSearchMode == enabled) return
        globalSearchMode = enabled
        search = ""
        selectedBranch?.let { loadProducts(it.id, effectiveCategoryId()) }
    }

    private var branchesJob: Job? = null
    private var categoriesJob: Job? = null
    private var productsJob: Job? = null
    private var branchesRequestId = 0
    private var categoriesRequestId = 0
    private var productsRequestId = 0

    init {
        loadBranches()
        loadCategories()
    }

    fun updateSearch(value: String) { search = value }

    fun selectCategory(category: Category) {
        selectedCategory = category
        search = ""
        // Only leaf categories contain products. Parent categories show their children.
        if (categories.any { it.parentId == category.id }) {
            productsJob?.cancel()
            productsRequestId++
            products = emptyList()
            productsError = null
            loadingProducts = false
        } else {
            selectedBranch?.let { loadProducts(it.id, effectiveCategoryId()) }
        }
    }

    fun navigateUpCategory() {
        val current = selectedCategory ?: return
        val parent = categories.find { it.id == current.parentId }
        if (parent == null) clearCategory() else selectCategory(parent)
    }

    fun clearCategory() {
        selectedCategory = null
        search = ""
        selectedBranch?.let { loadProducts(it.id, effectiveCategoryId()) }
    }

    fun selectBranch(branch: Branch) {
        if (selectedBranch?.id == branch.id) return
        selectedBranch = branch
        preferences.edit().putInt("selected_branch_id", branch.id).apply()
        products = emptyList()
        productsError = null
        if (globalSearchMode || selectedCategory == null ||
            categories.none { it.parentId == selectedCategory?.id }) {
            loadProducts(branch.id, effectiveCategoryId())
        }
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
                val current = selectedBranch
                val chosen = current?.let { old -> result.find { it.id == old.id } }
                    ?: result.find { it.id == savedBranchId }
                    ?: result.firstOrNull()
                if (chosen == null) {
                    selectedBranch = null
                    products = emptyList()
                    productsRequestId++
                    productsJob?.cancel()
                    loadingProducts = false
                } else if (selectedBranch?.id == chosen.id) {
                    selectedBranch = chosen
                } else {
                    selectBranch(chosen)
                }
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                if (requestId == branchesRequestId) {
                    branchesError = "Не удалось загрузить филиалы. Проверьте интернет."
                }
            } finally {
                if (requestId == branchesRequestId) loadingBranches = false
            }
        }
    }

    fun loadCategories() {
        categoriesRequestId++
        val requestId = categoriesRequestId
        categoriesJob?.cancel()
        categoriesJob = viewModelScope.launch {
            loadingCategories = true
            categoriesError = null
            try {
                val result = repository.getCategories()
                if (requestId != categoriesRequestId) return@launch
                categories = result
                val currentCategory = selectedCategory
                if (currentCategory != null) {
                    val updatedCategory = result.find { it.id == currentCategory.id }
                    if (updatedCategory == null) clearCategory()
                    else selectCategory(updatedCategory)
                }
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                if (requestId == categoriesRequestId) {
                    categoriesError = "Не удалось загрузить разделы каталога."
                }
            } finally {
                if (requestId == categoriesRequestId) loadingCategories = false
            }
        }
    }

    private fun loadProducts(branchId: Int, categoryId: Int?) {
        productsRequestId++
        val requestId = productsRequestId
        productsJob?.cancel()
        products = emptyList()
        productsJob = viewModelScope.launch {
            loadingProducts = true
            productsError = null
            try {
                val result = repository.getProducts(branchId, categoryId)
                if (requestId != productsRequestId || selectedBranch?.id != branchId ||
                    effectiveCategoryId() != categoryId) return@launch
                products = result
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                if (requestId == productsRequestId) {
                    productsError = "Не удалось загрузить товары. Попробуйте ещё раз."
                }
            } finally {
                if (requestId == productsRequestId) loadingProducts = false
            }
        }
    }

    fun retry() {
        if (branches.isEmpty()) loadBranches()
        if (categories.isEmpty()) loadCategories()
        if (branches.isNotEmpty() && (globalSearchMode || selectedCategory == null ||
                categories.none { it.parentId == selectedCategory?.id })) selectedBranch?.let {
            loadProducts(it.id, effectiveCategoryId())
        }
    }
}
