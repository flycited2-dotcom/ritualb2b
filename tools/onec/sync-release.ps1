[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$Processor,
    [Parameter(Mandatory)][string]$TemplateCode,
    [Parameter(Mandatory)][string]$ReceiptDirectory
)
$ErrorActionPreference = 'Stop'
$endpoint = [uri]$env:RITUAL_RELEASE_URL
if (-not $endpoint.IsAbsoluteUri -or ($endpoint.Scheme -ne 'https' -and -not $endpoint.IsLoopback)) {
    throw 'A trusted HTTPS endpoint is required.'
}
if (-not $env:RITUAL_1C_CONNECTION -or $env:RITUAL_RELEASE_1C_KEY.Length -lt 32) {
    throw 'Set RITUAL_1C_CONNECTION and RITUAL_RELEASE_1C_KEY in the service environment.'
}
$processorPath = (Resolve-Path -LiteralPath $Processor).Path
$receipts = [IO.Directory]::CreateDirectory($ReceiptDirectory).FullName
$utf8 = [Text.UTF8Encoding]::new($false)

function Invoke-ReleaseApi($action, $payload) {
    $json = ConvertTo-Json -InputObject $payload -Depth 10 -Compress
    $response = Invoke-RestMethod -Method Post -Uri ($endpoint.AbsoluteUri + '?action=' + $action) -MaximumRedirection 0 -TimeoutSec 60 `
        -Headers @{Authorization=('Bearer ' + $env:RITUAL_RELEASE_1C_KEY)} `
        -Body $utf8.GetBytes($json) -ContentType 'application/json; charset=utf-8'
    if (-not $response.ok) { throw "Release API failed on $action" }
    return $response
}

function Write-Receipt($path, $object) {
    $temporary = $path + '.tmp'
    [IO.File]::WriteAllText($temporary, (ConvertTo-Json -InputObject $object -Depth 10 -Compress), $utf8)
    if ([IO.File]::Exists($path)) { [IO.File]::Replace($temporary, $path, $null) }
    else { [IO.File]::Move($temporary, $path) }
}

function Complete-Receipt($pending) {
    $packet = [IO.File]::ReadAllText($pending) | ConvertFrom-Json
    $published = Invoke-ReleaseApi 'confirm' $packet
    $id = ([guid]$packet.release_id).ToString()
    if ($published.state -ne 'published' -or $published.release_id -ne $id) { throw 'Unexpected publication receipt.' }
    Write-Receipt (Join-Path $receipts ($id + '.json')) $published
    [IO.File]::Delete($pending)
    Write-Output "Published $id"
}

# A shared receipt directory serializes polling and preserves the exact payload over a lost reply.
$lock = [IO.File]::Open((Join-Path $receipts 'worker.lock'), 'OpenOrCreate', 'ReadWrite', 'None')
try {
    $hadErrors = $false
    foreach ($old in (Get-ChildItem -LiteralPath $receipts -Filter '*.pending.json')) {
        try { Complete-Receipt $old.FullName }
        catch { $hadErrors = $true; Write-Warning ("Pending receipt {0}: {1}" -f $old.Name,$_.Exception.Message) }
    }
    $cursor = @{}
    $session = $null
    while ($true) {
        $job = (Invoke-ReleaseApi 'next' $cursor).job
        if ($null -eq $job) { break }
        $id = ([guid]$job.release_id).ToString()
        $cursor = @{after_id=$id}
        $pending = Join-Path $receipts ($id + '.pending.json')
        if ([IO.File]::Exists($pending)) { continue }
        if ($null -eq $session) {
            $connector = New-Object -ComObject V83.COMConnector
            $session = $connector.Connect($env:RITUAL_1C_CONNECTION)
            if ($session.Metadata.Version -ne '11.5.26.106') { throw 'Verify the processor against this UT release first.' }
            $adapter = $session.ExternalDataProcessors.Create($processorPath)
        }
        try {
            $packet = $adapter.ОбработатьЗаявку((ConvertTo-Json -InputObject $job -Compress -Depth 10), $TemplateCode) | ConvertFrom-Json
            if (-not $packet.wholesale_price_rub) {
                $null = Invoke-ReleaseApi 'wait_price' @{release_id=$id; onec_id=$packet.onec_id}
                Write-Output "Waiting for wholesale price: $id"
                continue
            }
            Write-Receipt $pending $packet
            Complete-Receipt $pending
        } catch {
            $hadErrors = $true
            Write-Warning ("Release {0}: {1}" -f $id,$_.Exception.Message)
        }
    }
    if ($hadErrors) { throw 'Some releases require attention. See the warnings above.' }
} finally {
    $lock.Dispose()
}
