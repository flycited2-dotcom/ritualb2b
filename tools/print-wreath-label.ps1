[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string]$JobPath,
    [string]$LabelForgeDirectory = 'C:\LabelForge_TSC_Studio_PRO_TSC_TE200_1C',
    [string]$PrinterName = 'TSC TE200',
    [string]$ExportPath,
    [switch]$Print
)

$ErrorActionPreference = 'Stop'
if (-not $ExportPath -and -not $Print) { throw 'Specify -ExportPath or -Print.' }

$job = Get-Content -LiteralPath $JobPath -Raw -Encoding UTF8 | ConvertFrom-Json
$label = $job.label
if ($job.state -cne 'published' -or -not $label) { throw 'The job is waiting for 1C confirmation.' }
if ($label.copies -isnot [int] -and $label.copies -isnot [long]) { throw 'Invalid label quantity.' }
$copies = [int]$label.copies
if ($copies -lt 1 -or $copies -gt 1000) { throw 'Invalid label quantity.' }

$data = New-Object 'System.Collections.Generic.Dictionary[string,string]'
foreach ($field in @('name', 'dimensions', 'article', 'barcode')) {
    $value = $label.$field
    if ($value -isnot [string] -or [string]::IsNullOrWhiteSpace($value) -or $value -match '[\p{C}]') {
        throw "Invalid label field $field."
    }
    $data[$field] = $value
}

$code = $data['barcode']
switch -CaseSensitive ($label.barcode_type) {
    'code128' {
        if ($code -cnotmatch '^[A-Za-z0-9._-]{1,18}\z') { throw 'Invalid Code 128.' }
        $modules = 11 * ($code.Length + 2) + 13
        $narrowDots = 2
        $type = '128'
    }
    'ean13' {
        if ($code -cnotmatch '^[0-9]{13}\z') { throw 'Invalid EAN-13.' }
        $sum = 0
        for ($i = 0; $i -lt 12; $i++) { $sum += [int]::Parse($code.Substring($i, 1)) * (1 + 2 * ($i % 2)) }
        if ((10 - $sum % 10) % 10 -ne [int]::Parse($code.Substring(12))) { throw 'Invalid EAN-13 check digit.' }
        $data['barcode'] = $code.Substring(0, 12)
        $modules = 95
        $narrowDots = 3
        $type = 'EAN13'
    }
    default { throw 'Unsupported 1C barcode format.' }
}

if (-not ('LabelForge.TemplateIo' -as [type])) {
    Add-Type -Path (Join-Path $LabelForgeDirectory 'LabelForge.Core.cs') -ReferencedAssemblies @(
        'System.dll', 'System.Core.dll', 'System.Drawing.dll', 'System.Web.Extensions.dll'
    )
}
$template = [LabelForge.TemplateIo]::Load((Join-Path $PSScriptRoot 'labels/wreath_70x120.json'))
$barcode = $template.Elements | Where-Object { $_.Id -ceq 'barcode' }
$barcode.BarcodeType = $type
$barcode.BarcodeNarrowDots = $narrowDots
$barcode.BarcodeWideDots = $narrowDots
$barcode.Xmm = ($template.Page.WidthMm - $modules * $narrowDots / $template.Page.DotsPerMm()) / 2
$raw = [LabelForge.TsplRenderer]::Render($template, $data, $copies)

if ($ExportPath) {
    $fullPath = $ExecutionContext.SessionState.Path.GetUnresolvedProviderPathFromPSPath($ExportPath)
    [System.IO.File]::WriteAllBytes($fullPath, $raw)
}
if ($Print) {
    $printError = ''
    if (-not [LabelForge.RawPrinter]::Send($PrinterName, $raw, [ref]$printError)) { throw $printError }
    Write-Output "Print job sent to $PrinterName."
}
