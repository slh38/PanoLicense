# PanoLicense.Client NuGet paketini üretir (docs/CLIENT_PACKAGE.md).
# Kullanım (repo kökünden):  powershell -ExecutionPolicy Bypass -File tools\pack-client.ps1
# Çıktı: artifacts\nuget\PanoLicense.Client.<sürüm>.nupkg ve yanında .sha256 dosyası.
# Paket yalnız derlenmiş kütüphaneyi içerir; anahtar, parola, .env veya test dosyası içermez (betik kontrol eder).
param([switch]$AllowDirty)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot

# Resmî paket yalnız commit edilmiş temiz kaynaktan üretilir (nuspec içindeki commit kimliği kaynağı gösterir).
$dirty = git -C $root status --porcelain -- src/PanoLicense.Client
if ($dirty -and -not $AllowDirty) { throw "src/PanoLicense.Client içinde commit edilmemiş değişiklik var. Önce commit edin (deneme için -AllowDirty)." }
$project = Join-Path $root 'src\PanoLicense.Client\PanoLicense.Client.csproj'
$out = Join-Path $root 'artifacts\nuget'
New-Item -ItemType Directory -Force $out | Out-Null

# ContinuousIntegrationBuild: yerel yol bilgisi DLL'e girmez (yeniden üretilebilir derleme).
dotnet pack $project -c Release -o $out --nologo -p:ContinuousIntegrationBuild=true
if ($LASTEXITCODE -ne 0) { throw 'dotnet pack başarısız.' }

[xml]$csproj = Get-Content $project
$version = ($csproj.Project.PropertyGroup | Where-Object { $_.Version } | Select-Object -First 1).Version
$package = Join-Path $out "PanoLicense.Client.$version.nupkg"
if (-not (Test-Path $package)) { throw "Paket bulunamadı: $package" }

# İçerik denetimi: yalnız beklenen dosyalar, gizli bilgi yok.
Add-Type -AssemblyName System.IO.Compression.FileSystem
$zip = [System.IO.Compression.ZipFile]::OpenRead($package)
try {
    $names = $zip.Entries | ForEach-Object { $_.FullName }
    $unexpected = $names | Where-Object { $_ -notmatch '^(lib/net8\.0/PanoLicense\.Client\.dll|PanoLicense\.Client\.nuspec|README\.md|_rels/\.rels|\[Content_Types\]\.xml|package/services/metadata/core-properties/[^/]+\.psmdcp)$' }
    if ($unexpected) { throw "Pakette beklenmeyen dosya: $($unexpected -join ', ')" }
    foreach ($entry in $zip.Entries) {
        $reader = New-Object System.IO.StreamReader($entry.Open(), [System.Text.Encoding]::GetEncoding('ISO-8859-1'))
        $text = $reader.ReadToEnd(); $reader.Dispose()
        if ($text -match 'PRIVATE KEY|DEV_TEST_|DB_PASSWORD|APP_KEY=') { throw "Pakette gizli bilgi izi: $($entry.FullName)" }
    }
} finally { $zip.Dispose() }

$hash = (Get-FileHash $package -Algorithm SHA256).Hash.ToLowerInvariant()
Set-Content -Path "$package.sha256" -Value "$hash  PanoLicense.Client.$version.nupkg" -Encoding ascii
Write-Host "Paket: $package"
Write-Host "SHA-256: $hash"
Write-Host "Dosyalar: $($names -join ', ')"
