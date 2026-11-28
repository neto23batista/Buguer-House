param([string]$OutputDirectory = (Join-Path $PSScriptRoot '..\backups'))

$ErrorActionPreference = 'Stop'
if (-not $env:MYSQL_BACKUP_CONFIG -or -not (Test-Path -LiteralPath $env:MYSQL_BACKUP_CONFIG -PathType Leaf)) {
    throw 'Defina MYSQL_BACKUP_CONFIG com o caminho de um arquivo de opções privado do cliente MySQL.'
}
if (-not $env:DB_NAME -or $env:DB_NAME -notmatch '^[a-zA-Z0-9_-]+$') {
    throw 'Defina DB_NAME com o nome do banco a copiar.'
}
$configPath = (Resolve-Path -LiteralPath $env:MYSQL_BACKUP_CONFIG).Path
New-Item -ItemType Directory -Path $OutputDirectory -Force | Out-Null
$outputPath = Join-Path (Resolve-Path -LiteralPath $OutputDirectory).Path ($env:DB_NAME + '-' + [DateTime]::UtcNow.ToString('yyyyMMdd-HHmmss') + '-' + [Guid]::NewGuid().ToString('N').Substring(0, 8) + '.sql')
& mysqldump "--defaults-extra-file=$configPath" --hex-blob --single-transaction --quick --routines --triggers --events --no-tablespaces --set-gtid-purged=OFF --default-character-set=utf8mb4 "--result-file=$outputPath" $env:DB_NAME
if ($LASTEXITCODE -ne 0) {
    if (Test-Path -LiteralPath $outputPath -PathType Leaf) {
        Remove-Item -LiteralPath $outputPath
    }
    throw 'O backup falhou. Nenhum arquivo parcial foi mantido.'
}
Write-Output $outputPath
