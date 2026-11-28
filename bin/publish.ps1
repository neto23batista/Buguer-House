param([Parameter(Mandatory)][string]$MigrationEnv)

$ErrorActionPreference = 'Stop'
$repository = Split-Path $PSScriptRoot -Parent
$credentials = (Resolve-Path -LiteralPath $MigrationEnv).Path
$link = Join-Path $repository '.vercel/project.json'
$project = Get-Content -Raw -LiteralPath $link | ConvertFrom-Json
$commit = git -C $repository rev-parse --verify HEAD
if ($LASTEXITCODE -ne 0) { throw 'Não foi possível identificar a revisão Git.' }

$tempRoot = (Resolve-Path -LiteralPath ([IO.Path]::GetTempPath())).Path
$folderName = 'burger-release-' + [Guid]::NewGuid().ToString('N')
$staging = Join-Path $tempRoot $folderName
New-Item -ItemType Directory -Path $staging | Out-Null
try {
    $archive = Join-Path $staging 'source.zip'
    $source = Join-Path $staging 'source'
    git -C $repository archive --format=zip "--output=$archive" $commit
    if ($LASTEXITCODE -ne 0) { throw 'Falha ao exportar a revisão.' }
    Expand-Archive -LiteralPath $archive -DestinationPath $source
    $image = 'burger-house:release-' + $commit.Substring(0, 12)
    docker build --quiet --tag $image $source
    if ($LASTEXITCODE -ne 0) { throw 'Falha ao construir a imagem desta revisão.' }
    docker run --rm --env-file $credentials $image php bin/migrate.php --check
    if ($LASTEXITCODE -ne 0) { throw 'Publicação interrompida na verificação do banco.' }

    $projectDir = New-Item -ItemType Directory -Path (Join-Path $source '.vercel')
    Copy-Item -LiteralPath $link -Destination $projectDir.FullName
    $deployment = vercel deploy --prod --skip-domain --yes --cwd $source --scope $project.orgId --meta "release=$commit"
    if ($LASTEXITCODE -ne 0) { throw 'Falha ao preparar o deploy de produção.' }
    $url = ($deployment | Select-Object -Last 1).Trim()
    $health = vercel curl /health.php --deployment $url --cwd $source --scope $project.orgId -- --fail --silent --show-error
    if ($LASTEXITCODE -ne 0 -or ($health | ConvertFrom-Json).status -ne 'ok') {
        throw 'A nova versão falhou na verificação de saúde antes da promoção.'
    }
    vercel promote $url --yes --cwd $source --scope $project.orgId
    if ($LASTEXITCODE -ne 0) { throw 'A Vercel não confirmou a promoção.' }
    Write-Output "Revisão publicada: $commit"
} finally {
    $resolved = (Resolve-Path -LiteralPath $staging).Path
    if ((Split-Path $resolved -Leaf) -eq $folderName -and (Split-Path $resolved -Parent) -eq $tempRoot.TrimEnd([IO.Path]::DirectorySeparatorChar)) {
        Remove-Item -LiteralPath $resolved -Recurse -Force
    }
}
