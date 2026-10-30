param(
    [Parameter(Mandatory=$true)][string]$ProjectRoot,
    [Parameter(Mandatory=$true)][string]$PhpPath,
    [Parameter(Mandatory=$true)][string]$PhpIni,
    [Parameter(Mandatory=$true)][string]$BackupDirectory,
    [string]$DailyAt = '02:00',
    [switch]$Install
)
$ErrorActionPreference = 'Stop'
$ProjectRoot = (Resolve-Path -LiteralPath $ProjectRoot).Path.TrimEnd('\')
$PhpPath = (Resolve-Path -LiteralPath $PhpPath).Path
$PhpIni = (Resolve-Path -LiteralPath $PhpIni).Path
$BackupDirectory = (Resolve-Path -LiteralPath $BackupDirectory).Path.TrimEnd('\')
if ($BackupDirectory.Equals($ProjectRoot,[StringComparison]::OrdinalIgnoreCase) -or $BackupDirectory.StartsWith($ProjectRoot+'\',[StringComparison]::OrdinalIgnoreCase)) { throw 'Backup directory must be outside the application/web root.' }
if (!$env:LCCT_GUIDANCE_BACKUP_KEY) { throw 'Configure the private LCCT_GUIDANCE_BACKUP_KEY environment variable first. Never put a key in this script, source control or task arguments.' }
$scriptPath = Join-Path $ProjectRoot 'guidance\schedule-restoration.ps1'
if (!(Test-Path -LiteralPath (Join-Path $ProjectRoot 'guidance\restoration-ops.php'))) { throw 'Wrong source directory.' }
if ($Install) {
    $name = 'LCCT-Guidance-Verified-Subset-Backup'
    if (Get-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue) { throw 'A task with this name already exists. Review it manually; this script will not overwrite it.' }
    $time = [DateTime]::ParseExact($DailyAt,'HH:mm',[Globalization.CultureInfo]::InvariantCulture)
    $arguments = '-NoProfile -NonInteractive -File "{0}" -ProjectRoot "{1}" -PhpPath "{2}" -PhpIni "{3}" -BackupDirectory "{4}"' -f $scriptPath,$ProjectRoot,$PhpPath,$PhpIni,$BackupDirectory
    $action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument $arguments
    $trigger = New-ScheduledTaskTrigger -Daily -At $time
    $principal = New-ScheduledTaskPrincipal -UserId ([Security.Principal.WindowsIdentity]::GetCurrent().Name) -LogonType Interactive -RunLevel Limited
    $settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -StartWhenAvailable
    Register-ScheduledTask -TaskName $name -Action $action -Trigger $trigger -Principal $principal -Settings $settings | Out-Null
    Write-Host 'Guidance-only task registered. It runs only while this user is logged on. It does not install a system service, store account passwords or schedule full-system restores.'
    return
}
Push-Location $ProjectRoot
try {
    & $PhpPath -c $PhpIni guidance/jobs.php --apply
    if ($LASTEXITCODE -ne 0) { throw 'Guidance job failed; inspect its output.' }
    $name = 'Guidance-' + (Get-Date -Format 'yyyyMMdd-HHmmss-fff') + '-' + [guid]::NewGuid().ToString('N') + '.lcctgd'
    $file = Join-Path $BackupDirectory $name
    & $PhpPath -c $PhpIni guidance/backup.php create $file
    if ($LASTEXITCODE -ne 0) { throw 'Backup creation failed; no success marker recorded.' }
    & $PhpPath -c $PhpIni guidance/restoration-ops.php $file
    if ($LASTEXITCODE -ne 0) { throw 'Backup verification failed; no success marker recorded.' }
    Write-Host 'Guidance job and verified encrypted subset backup completed. Keep encryption keys separately. No retention deletion or full restore was performed.'
} finally { Pop-Location }
