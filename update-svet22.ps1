# Svet22 Android catalog updater
# Run from any directory: powershell -ExecutionPolicy Bypass -File .\update-svet22.ps1
$ErrorActionPreference = 'Stop'
$project = 'C:\Users\1\Downloads\svet22-android\android'
$repo = 'https://raw.githubusercontent.com/Stran22/svetofor22/work/backend-core'
$source = 'app/src/main/java/ru/svet22/app'
$files = @(
    'data/model/Category.kt',
    'data/model/Branch.kt',
    'data/model/Product.kt',
    'data/model/ApiEnvelope.kt',
    'data/remote/RetrofitClient.kt',
    'data/remote/SvetApi.kt',
    'data/repository/ProductRepository.kt',
    'ui/catalog/CatalogViewModel.kt',
    'ui/catalog/CatalogScreen.kt',
    'ui/catalog/ProductDetailScreen.kt',
    'navigation/AppNavigation.kt'
)
if (-not (Test-Path -LiteralPath $project -PathType Container)) {
    throw "Android project directory not found: $project"
}
$backupRoot = Join-Path $project ('svet22-backups\' + (Get-Date -Format 'yyyyMMdd-HHmmss'))
$changed = 0
$unchanged = 0
foreach ($file in $files) {
    $relative = "$source/$file"
    $destination = Join-Path $project ($relative.Replace('/', '\'))
    # Unique query prevents stale cached Raw GitHub responses.
    $url = "$repo/$relative?refresh=$([guid]::NewGuid().ToString('N'))"
    $temp = [System.IO.Path]::GetTempFileName()
    try {
        Invoke-WebRequest -Uri $url -OutFile $temp -UseBasicParsing -ErrorAction Stop
        $same = (Test-Path -LiteralPath $destination) -and (
            (Get-FileHash -LiteralPath $destination -Algorithm SHA256).Hash -eq
            (Get-FileHash -LiteralPath $temp -Algorithm SHA256).Hash
        )
        if ($same) {
            Write-Host "UNCHANGED: $file" -ForegroundColor Gray
            $unchanged++
            continue
        }
        if (Test-Path -LiteralPath $destination) {
            $backup = Join-Path $backupRoot ($relative.Replace('/', '\'))
            New-Item -ItemType Directory -Force -Path (Split-Path $backup -Parent) | Out-Null
            Copy-Item -LiteralPath $destination -Destination $backup -Force
        }
        New-Item -ItemType Directory -Force -Path (Split-Path $destination -Parent) | Out-Null
        Copy-Item -LiteralPath $temp -Destination $destination -Force
        Write-Host "UPDATED: $file" -ForegroundColor Green
        $changed++
    }
    catch {
        Write-Host "FAILED: $file - $($_.Exception.Message)" -ForegroundColor Red
        throw
    }
    finally {
        Remove-Item -LiteralPath $temp -Force -ErrorAction SilentlyContinue
    }
}
Write-Host "Done. Updated: $changed; unchanged: $unchanged" -ForegroundColor Cyan
if ($changed -gt 0) { Write-Host "Backups (for replaced files): $backupRoot" }
