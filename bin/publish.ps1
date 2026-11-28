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
    $run = [Guid]::NewGuid().ToString('N')
    vercel deploy $source --prod --skip-domain --yes --cwd $repository --scope $project.orgId --meta "release=$commit" --meta "publishRun=$run" | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Falha ao preparar o deploy de produção.' }
    $route = '/v7/deployments?teamId=' + $project.orgId + '&projectId=' + $project.projectId + '&target=production&meta-publishRun=' + $run
    $records = vercel api $route --method GET | ConvertFrom-Json
    $candidate = @($records.deployments | Where-Object { $_.meta.publishRun -eq $run -and $_.meta.release -eq $commit -and $_.readyState -eq 'READY' })
    if ($LASTEXITCODE -ne 0 -or $candidate.Count -ne 1 -or $candidate[0].url -notmatch '^[a-zA-Z0-9-]+\.vercel\.app$') {
        throw 'A Vercel não confirmou o candidato desta revisão.'
    }
    $healthUrl = 'https://' + $candidate[0].url + '/health.php'
    $protection = '/v1/projects/' + $project.projectId + '/protection-bypass?teamId=' + $project.orgId
    $note = "release-health-$run"
    $issued = @{generate=@{note=$note}} | ConvertTo-Json -Compress | vercel api $protection --method PATCH --input - | ConvertFrom-Json
    $access = @($issued.protectionBypass.PSObject.Properties | Where-Object { $_.Value.note -eq $note })
    if ($LASTEXITCODE -ne 0 -or $access.Count -ne 1) { throw 'Falha ao autorizar a verificação do candidato.' }
    try {
        # A newly issued credential may take a moment to reach the edge.
        for ($attempt = 1; $attempt -le 4; $attempt++) {
            try {
                $health = Invoke-RestMethod -Uri $healthUrl -Headers @{'x-vercel-protection-bypass'=$access[0].Name} -MaximumRedirection 0 -TimeoutSec 15 -ErrorAction Stop
                break
            } catch {
                if ($attempt -eq 4) { throw }
                Start-Sleep -Seconds 2
            }
        }
        if ($health.status -ne 'ok') { throw 'Falha na verificação de saúde do candidato.' }
    } finally {
        @{revoke=@{secret=$access[0].Name;regenerate=$false}} | ConvertTo-Json -Compress | vercel api $protection --method PATCH --input - | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'Falha ao revogar a credencial temporária da verificação.' }
    }
    vercel promote $candidate[0].uid --yes --cwd $repository --scope $project.orgId
    if ($LASTEXITCODE -ne 0) { throw 'A Vercel não confirmou a promoção.' }
    Write-Output "Revisão publicada: $commit"
} finally {
    $resolved = (Resolve-Path -LiteralPath $staging).Path
    if ((Split-Path $resolved -Leaf) -eq $folderName -and (Split-Path $resolved -Parent) -eq $tempRoot.TrimEnd([IO.Path]::DirectorySeparatorChar)) {
        for ($attempt = 1; $attempt -le 5; $attempt++) {
            try { Remove-Item -LiteralPath $resolved -Recurse -Force -ErrorAction Stop; break }
            catch [IO.IOException] {
                if ($attempt -eq 5) { Write-Warning "Pasta temporária ainda em uso: $resolved"; break }
                Start-Sleep -Milliseconds 500
            }
        }
    }
}
