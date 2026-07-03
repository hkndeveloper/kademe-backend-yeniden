param(
    [string]$OutputPath = (Join-Path $PSScriptRoot '..\..\openapi-delivery')
)

$ErrorActionPreference = 'Stop'

$backendRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
$sourceDir = Join-Path $backendRoot 'storage\app\private\scribe'
$openApiSource = Join-Path $sourceDir 'openapi.yaml'
$collectionSource = Join-Path $sourceDir 'collection.json'

if (-not (Test-Path $openApiSource) -or -not (Test-Path $collectionSource)) {
    throw 'Scribe outputs were not found. Run `php artisan scribe:generate` from backend first.'
}

$output = New-Item -ItemType Directory -Force -Path $OutputPath
$openApiTarget = Join-Path $output.FullName 'openapi.yaml'
$collectionTarget = Join-Path $output.FullName 'collection.json'

Copy-Item -LiteralPath $openApiSource -Destination $openApiTarget -Force
Copy-Item -LiteralPath $collectionSource -Destination $collectionTarget -Force

$files = @($openApiTarget, $collectionTarget) | ForEach-Object {
    $item = Get-Item $_
    $hash = Get-FileHash -LiteralPath $_ -Algorithm SHA256
    [ordered]@{
        name = $item.Name
        bytes = $item.Length
        sha256 = $hash.Hash
    }
}

$manifest = [ordered]@{
    generated_at = (Get-Date).ToString('yyyy-MM-dd HH:mm:ss zzz')
    source = 'backend/storage/app/private/scribe'
    note = 'Regenerate with `php artisan scribe:generate`, then rerun this script before delivery.'
    files = $files
}

$json = $manifest | ConvertTo-Json -Depth 6
[System.IO.File]::WriteAllText((Join-Path $output.FullName 'manifest.json'), $json, [System.Text.UTF8Encoding]::new($false))

Write-Output "OpenAPI delivery exported to $($output.FullName)"