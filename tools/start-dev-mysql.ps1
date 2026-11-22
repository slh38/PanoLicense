# Yerel geliştirme MySQL 8.4 sunucusunu başlatır (yalnız 127.0.0.1:3306).
# Veri ve ayar: %LOCALAPPDATA%\PanoLicense\mysql (repoda değil). Durdurmak için pencereyi kapatın
# veya: & "C:\Program Files\MySQL\MySQL Server 8.4\bin\mysqladmin.exe" --defaults-extra-file="$env:LOCALAPPDATA\PanoLicense\mysql\root.cnf" shutdown

$mysqld = 'C:\Program Files\MySQL\MySQL Server 8.4\bin\mysqld.exe'
$ini = Join-Path $env:LOCALAPPDATA 'PanoLicense\mysql\my.ini'

if (-not (Test-Path $mysqld)) { throw "MySQL Server 8.4 bulunamadı: $mysqld" }
if (-not (Test-Path $ini)) { throw "Yerel MySQL ayarı bulunamadı: $ini" }
if (Get-NetTCPConnection -LocalPort 3306 -State Listen -ErrorAction SilentlyContinue) {
    Write-Host '3306 portu zaten dinleniyor; MySQL çalışıyor olabilir.'
    return
}

Start-Process -FilePath $mysqld -ArgumentList "--defaults-file=`"$ini`"" -WindowStyle Minimized
Write-Host 'MySQL başlatıldı: 127.0.0.1:3306'
