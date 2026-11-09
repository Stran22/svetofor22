# Svet22 Android updater - GitHub API, verified blobs
$ErrorActionPreference = 'Stop'
$project = $PSScriptRoot
$ownerRepo = 'Stran22/svetofor22'
$branch = 'work/backend-core'
$source = 'app/src/main'
# Automatically discover Android source files and resources under app/src/main.
# Build files, secrets, and other local files are intentionally excluded.
if (-not (Test-Path -LiteralPath (Join-Path $project 'app') -PathType Container)) {
    throw "Run this updater from the Android project folder (script location): $project"
}
$headers = @{ 'User-Agent' = 'Svet22-Android-Updater'; 'Accept' = 'application/vnd.github+json'; 'Cache-Control' = 'no-cache' }
function Get-GitHubJson([string]$uri) {
    $separator = if ($uri.Contains('?')) { '&' } else { '?' }
    $nonce = [guid]::NewGuid().ToString('N')
    return Invoke-RestMethod -Uri ("$uri$separator" + "nonce=$nonce") -Headers $headers -UseBasicParsing -ErrorAction Stop
}
function Get-GitBlobSha([byte[]]$bytes) {
    $prefix = [System.Text.Encoding]::ASCII.GetBytes("blob $($bytes.Length)`0")
    $all = New-Object byte[] ($prefix.Length + $bytes.Length)
    [Array]::Copy($prefix, 0, $all, 0, $prefix.Length)
    [Array]::Copy($bytes, 0, $all, $prefix.Length, $bytes.Length)
    $sha = [System.Security.Cryptography.SHA1]::Create()
    try { return ([BitConverter]::ToString($sha.ComputeHash($all))).Replace('-', '').ToLowerInvariant() }
    finally { $sha.Dispose() }
}
# Pin all files to one exact commit. Never mix revisions during an update.
$branchRef = [uri]::EscapeDataString($branch)
$commit = Get-GitHubJson "https://api.github.com/repos/$ownerRepo/commits/$branchRef"
$revision = [string]$commit.sha
if ($revision -notmatch '^[0-9a-f]{40}$') { throw 'GitHub did not return a valid commit SHA.' }
Write-Host "GitHub revision: $revision" -ForegroundColor Cyan
$tree = Get-GitHubJson ("https://api.github.com/repos/{0}/git/trees/{1}?recursive=1" -f $ownerRepo, $revision)
if ($tree.truncated -eq $true) { throw 'GitHub tree is truncated; update aborted.' }
$prefix = "$source/"
$entries = @($tree.tree | Where-Object {
    $_.type -eq 'blob' -and $_.path.StartsWith($prefix, [System.StringComparison]::Ordinal) -and
    (-not $_.path.EndsWith('/.gitkeep'))
} | Sort-Object path)
if ($entries.Count -eq 0) { throw "No files found under $source in GitHub. Update aborted." }
Write-Host "Discovered Android files: $($entries.Count)" -ForegroundColor Cyan
$backupRoot = Join-Path $project ('svet22-backups\' + (Get-Date -Format 'yyyyMMdd-HHmmss'))
$changed = 0
$unchanged = 0
foreach ($entry in $entries) {
    $relative = [string]$entry.path
    $file = $relative.Substring($prefix.Length)
    if ($relative -match '(^|/)\\.\\.(/|$)' -or $relative -match '[\\x00-\\x1f]') { throw "Unsafe repository path: $relative" }
    $destination = Join-Path $project ($relative.Replace('/', '\'))
    $temp = [System.IO.Path]::GetTempFileName()
    try {
        $encodedPath = ($relative.Split('/') | ForEach-Object { [uri]::EscapeDataString($_) }) -join '/'
        $info = Get-GitHubJson ("https://api.github.com/repos/{0}/contents/{1}?ref={2}" -f $ownerRepo, $encodedPath, $revision)
        if ($info.type -ne 'file' -or $info.encoding -ne 'base64') {
            throw "Unexpected GitHub API response for $file"
        }
        $bytes = [Convert]::FromBase64String(($info.content -replace '\s', ''))
        $actualBlob = Get-GitBlobSha $bytes
        if ($actualBlob -ne ([string]$info.sha).ToLowerInvariant() -or $actualBlob -ne ([string]$entry.sha).ToLowerInvariant()) {
            throw "Downloaded file failed SHA-1 verification: $file"
        }
        [System.IO.File]::WriteAllBytes($temp, $bytes)
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
    finally { Remove-Item -LiteralPath $temp -Force -ErrorAction SilentlyContinue }
}
Write-Host "Done. Updated: $changed; unchanged: $unchanged" -ForegroundColor Cyan
if ($changed -gt 0) { Write-Host "Backups: $backupRoot" }

} | Sort-Object path)
if ($entries.Count -eq 0) { throw "No files found under $source in GitHub. Update aborted." }
Write-Host "Discovered Android files: $($entries.Count)" -ForegroundColor Cyan

$backupRoot = Join-Path $project ('svet22-backups\' + (Get-Date -Format 'yyyyMMdd-HHmmss'))
$changed = 0
$unchanged = 0
foreach ($file in $files) {
    $relative = "$source/$file"
    $destination = Join-Path $project ($relative.Replace('/', '\'))
    $temp = [System.IO.Path]::GetTempFileName()
    try {
        $encodedPath = ($relative.Split('/') | ForEach-Object { [uri]::EscapeDataString($_) }) -join '/'
        $info = Get-GitHubJson ("https://api.github.com/repos/{0}/contents/{1}?ref={2}" -f $ownerRepo, $encodedPath, $revision)
        if ($info.type -ne 'file' -or $info.encoding -ne 'base64') {
            throw "Unexpected GitHub API response for $file"
        }
        $bytes = [Convert]::FromBase64String(($info.content -replace '\s', ''))
        $actualBlob = Get-GitBlobSha $bytes
        if ($actualBlob -ne ([string]$info.sha).ToLowerInvariant()) {
            throw "Downloaded file failed SHA-1 verification: $file"
        }
        [System.IO.File]::WriteAllBytes($temp, $bytes)
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
    finally { Remove-Item -LiteralPath $temp -Force -ErrorAction SilentlyContinue }
}
Write-Host "Done. Updated: $changed; unchanged: $unchanged" -ForegroundColor Cyan
if ($changed -gt 0) { Write-Host "Backups: $backupRoot" }
