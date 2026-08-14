$ErrorActionPreference = "SilentlyContinue"
$ProgressPreference = "SilentlyContinue"

# TLS 1.2 is forced: Windows PowerShell 5.1 defaults to SSL3/TLS1.0, which makes
# the result callback (Invoke-RestMethod) fail silently against a modern panel.
try {
    [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
} catch {}

# ============================================================================
#  CHEAT CHECK SCANNER v3.0
#  The panel fills in the panel URL, API key and scan token placeholders
#  before serving this file (see CheatCheckService::serveScript). Do not
#  repeat those placeholder tokens in comments - they get substituted too,
#  which would print the API key into the player's console.
# ============================================================================

#region Elevation ------------------------------------------------------------

# NOTE: forcing administrator rights (UAC) does not happen in this file. The
# panel prepends a small bootstrap that re-runs the same URL elevated and then
# exits the unelevated process (see CheatCheckService::elevationBootstrap).
#
# All this script does here is check whether its own context is elevated. If it
# is not - the bootstrap's UAC prompt may have been declined - it WARNS and
# continues in LIMITED MODE (driver/memory/Amcache layers are skipped, it does
# not abort).
$isAdmin = $false
try {
    $isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
} catch {}

if (-not $isAdmin) {
    Write-Host ""
    Write-Host "  [!] WARNING: this session is NOT running as administrator (UAC declined?)." -ForegroundColor Red
    Write-Host "      Driver / memory / Amcache layers will be SKIPPED (limited scan)." -ForegroundColor Red
    Write-Host ""
    Start-Sleep -Seconds 2
}

#endregion

#region Console setup --------------------------------------------------------

# QuickEdit is turned off: it is on by default, so as soon as the user (or a
# mouse event arriving over TeamViewer/AnyDesk) clicks into the console and
# selects text, Windows genuinely BLOCKS the WriteConsole() calls - the script
# itself freezes, and pressing a key flushes everything at once. That is the
# real cause of "it hangs, then speeds up when I press a key". Add-Type throws
# if the type already exists (same session, second run), hence the guard. A
# failure is not swallowed silently - it is recorded in scanNotes so the admin
# can see it in the panel.
#
# IMPORTANT: this is re-applied PERIODICALLY, not once. The script spawns
# external processes (reg.exe, 7z.exe, Rar.exe) and COM objects (WScript.Shell
# for shortcut analysis); any of them can inherit the console handle, set its
# own console mode, and silently turn QuickEdit back on - which matches the
# "scan starts fine, then freezes halfway" reports exactly.
$script:quickEditDisabled = $false

function Set-QuickEditDisabled {
    try {
        if (-not ('CheatCheck.ConsoleUtils' -as [type])) {
            Add-Type -Namespace CheatCheck -Name ConsoleUtils -MemberDefinition @'
[DllImport("kernel32.dll", SetLastError = true)]
public static extern IntPtr GetStdHandle(int nStdHandle);
[DllImport("kernel32.dll")]
public static extern bool GetConsoleMode(IntPtr hConsoleHandle, out uint lpMode);
[DllImport("kernel32.dll")]
public static extern bool SetConsoleMode(IntPtr hConsoleHandle, uint dwMode);
'@ -ErrorAction Stop
        }
        $stdInHandle = [CheatCheck.ConsoleUtils]::GetStdHandle(-10)
        $consoleMode = 0
        if ([CheatCheck.ConsoleUtils]::GetConsoleMode($stdInHandle, [ref]$consoleMode)) {
            # Clears QuickEdit (0x0040) AND mouse input (0x0010) - the second one
            # covers Mark Mode being triggered by dragging alone on some console
            # builds even with QuickEdit off. ExtendedFlags (0x0080) is required.
            $newMode = ($consoleMode -band (-bnot 0x0040) -band (-bnot 0x0010)) -bor 0x0080
            [void][CheatCheck.ConsoleUtils]::SetConsoleMode($stdInHandle, $newMode)
            $verifyMode = 0
            if ([CheatCheck.ConsoleUtils]::GetConsoleMode($stdInHandle, [ref]$verifyMode)) {
                $script:quickEditDisabled = (($verifyMode -band 0x0040) -eq 0)
            }
        }
    } catch {}
}

Set-QuickEditDisabled

#endregion

#region Global state ---------------------------------------------------------

$script:isAdmin        = $isAdmin
$scanStart             = Get-Date
$script:findings       = New-Object System.Collections.Generic.List[object]
$script:seenFindings   = @{}
$script:riskScore      = 0
$script:confirmedCheat = $false
$script:partialScan    = $false
$script:scanNotes      = New-Object System.Collections.Generic.List[string]

if (-not $script:quickEditDisabled) {
    $script:scanNotes.Add("QuickEdit Mode could not be disabled; clicking the console may pause the scan briefly.")
}

# Overall time budget. Without one, scans ran 30+ minutes on some machines
# (Get-AuthenticodeSignature performing CRL/OCSP network lookups).
$script:TotalBudgetSec = 900
if ($env:CHEAT_CHECK_BUDGET_SEC -and ([int]::TryParse($env:CHEAT_CHECK_BUDGET_SEC, [ref]$null))) {
    $script:TotalBudgetSec = [int]$env:CHEAT_CHECK_BUDGET_SEC
}
$script:Deadline       = $scanStart.AddSeconds($script:TotalBudgetSec)
$script:StepDeadline   = $script:Deadline
$script:StepTimedOut   = $false

# Caches: signature/hash/PE data was being re-queried for the same file over and
# over. Get-AuthenticodeSignature is expensive, so this alone is a big speed-up.
$script:sigCache  = @{}
$script:hashCache = @{}
$script:peCache   = @{}

$logFile = Join-Path $env:TEMP "CheatCheck_Scan_$(Get-Date -Format 'yyyy-MM-dd_HH-mm-ss').log"
$script:logWriter = $null
try {
    $script:logWriter = New-Object System.IO.StreamWriter($logFile, $false, [System.Text.UTF8Encoding]::new($false))
    $script:logWriter.AutoFlush = $true
} catch {}

#endregion

#region Keyword tiers --------------------------------------------------------

# Keywords are split into three tiers. Matching them all as plain substrings
# produced enormous false-positive volume: 'loader' hit "fabric-loader.jar",
# 'dma' hit "admanager.dll", 'fanta' hit "Fantasy Grounds", 'orbit' hit "Orbit
# Downloader". Now: Strong = the brand itself (substring is safe), Medium = a
# real cheat term (word boundary), Weak = generic term (never a finding alone).

# Strong (loose): long, distinctive brand names. They cannot plausibly occur
# inside an ordinary English word, so substring matching is safe and compound
# names like "neverlosev2.exe" or "gamesense_crack.exe" are still caught.
$script:kwStrongLoose = @(
    'neverlose','gamesense','memesense','primordial','csghost','redtorch','exloader',
    'rawetrip','ezfrags','millionware','spirthack','kernaim','legendware','systemcheats',
    'phantomoverlay','kdmapper','pcileech','leechcore','gamesneeze','sapgoggles',
    'captaindma','enigmadma','lambdadma','terminatordma','raptordma','duckdma','divinedma',
    'emudma','unknowncheats','cheatglobal','cheatautomation','ring1loader','vacbypass',
    'crimson cheats','venom cheats','matrix softworks','redline cheats','valkyrie cheats',
    'rebellion cheats','ownage pro','gowin cheats','exort cheats','kinetic cheats',
    'covenant cheats','reversal cheats','byfron bypass','nightfall dma','clutch solution',
    'fantasy cat','phantom overlay','extreme injector'
)

# Strong (exact): brand names that are short or can appear inside other words.
# These MUST be matched on word boundaries:
#   'tsearch'  -> matched "tSearch" inside "DepthFirstSearch.html"
#   'directma' -> matched Windows' own "DirectManipulation.dll"
# Both produced real false positives during smoke testing.
$script:kwStrongExact = @(
    'tsearch','directma','xenos','osiris','skeet','onetap','aimware','nixware',
    'fatality','iniuria','interweb','artmoney','keyauth','limeauth'
)

# Medium: actual cheat behaviour terms. Matched on word boundaries.
$script:kwMedium = @(
    'aimbot','wallhack','wallhax','triggerbot','ragebot','legitbot','silentaim','silent aim',
    'norecoil','no recoil','radarhack','radar hack','glowesp','glow esp','esp overlay',
    'crosshair esp','skinchanger','skin changer','autobhop','autostrafe','colorbot',
    'instantaim','instant aim','injector','hwid spoofer','vac bypass','eac bypass',
    'anticheat bypass','vanguard bypass','easyanticheat bypass','trust factor bypass',
    'manualmap','manual map','driver mapper','process hollowing','dll injection',
    'bootkit loader','vmread','vmwrite','kdriver','aimstar','midnight','pandora','sensum',
    'interium','legendware','hexui','lethality','obstructions','skush','airflow'
)

# Weak: generic terms. NEVER a finding on their own; they only act as
# supporting evidence next to another suspicious signal (unsigned + risky
# location, and so on).
$script:kwWeak = @(
    'cheat','hack','loader','mapper','injector','spoofer','bypass','dma','trigger','bhop',
    'orbit','plague','weave','oxide','clarity','luno','anyx','fanta','fantasy','phantom',
    'clutch','nightfall','ekknod','battlelog','ring0','ring1','leaked','crack'
)

# Cheat licensing / storefront / forum domains (DNS + browser history + Zone.Identifier).
$script:cheatDomains = @(
    'keyauth.win','keyauth.com','keyauth.cc','auth.gg','limeauth.net','shoppy.gg',
    'neverlose.cc','midnight.im','iniuria.us','aimware.net','primordial.dev','gamesense.pub',
    'skeet.cc','orbit.bot','plague.club','weave.su','spirthack.me','kernaim.to',
    'exloader.net','rawetrip.cc','ezfrags.co','phantomoverlay.io','fantasy.cat',
    'clutch-solution.com','lethality.io','anyx.gg','sensum.moe','pandora.gg',
    'systemcheats.net','battlelog.co','legendware.net','onetap.com','memesense.ru',
    'unknowncheats.me','cheatglobal.com','cheatautomation.com','hackforums.net','mpgh.net',
    'elitepvpers.com','captaindma.com','enigma-x1.com','ftdichip.com'
)

#endregion

#region Exclusion engine -----------------------------------------------------

# Plain substring exclusion caused critical false NEGATIVES:
#   'eac'  -> excluded EVERY path containing "reach", "peace", "bleach"
#   'git'  -> excluded every path containing "digital", "legit"
#   'cheat engine' -> excluded Cheat Engine itself from detection (!)
# There are now two separate mechanisms: path-SEGMENT matching and word-boundary
# matching.

# Terms that must match a whole path segment (folder/file name).
$script:excludedSegments = @(
    'node_modules','winsxs','driverstore','windowsapps','apprepository','packagecache',
    '$recycle.bin','system volume information','servicing','assembly','dotnet','sdk',
    'microsoft visual studio','jetbrains','pycharm','intellij','rider','webstorm',
    'vscode','.vscode','.git','.gradle','.nuget','.cargo','.rustup','venv','.venv',
    'site-packages','__pycache__','anaconda3','miniconda3'
)

# Vendor directories - path-shaped patterns where a substring match is safe.
$script:excludedPathParts = @(
    '\windows\winsxs\','\windows\servicing\','\windows\system32\driverstore\',
    '\windows\assembly\','\program files\windowsapps\','\program files\nvidia corporation\',
    '\program files\nvidia\','\program files (x86)\nvidia corporation\',
    '\program files\amd\','\program files (x86)\amd\','\program files\intel\',
    '\program files (x86)\intel\','\program files\common files\microsoft shared\',
    '\program files\windows defender\','\program files\google\chrome\',
    '\program files (x86)\google\chrome\','\program files (x86)\microsoft\edge\',
    '\program files\mozilla firefox\','\program files (x86)\steam\',
    '\steamapps\common\','\epic games\','\riot games\','\ubisoft\','\gog galaxy\',
    '\battle.net\','\ea games\','\electronic arts\','\obs-studio\','\adobe\',
    '\autodesk\','\jetbrains\','\docker\','\wsl\','\windowsapps\'
)

# Short/risky terms that must match on word boundaries.
$script:excludedWords = @(
    'easyanticheat','eanticheat','eaanticheat','eac','battleye','vanguard','vgk','faceit',
    'esea','anticheatexpert','myacdrv','windivert','webview2','ghub','lghub','logi',
    'razer','steelseries','corsair','hyperx','icue','armoury','powertoys','windhawk',
    'altsnap','flowlauncher','zapret','winws','goodbyedpi','splitwire','bluestacks',
    'nox','ldplayer','claude','cursor','copilot','antigravity','prismlauncher',
    'minecraft','forge','fabric','kubejs','roblox','bakkesmod'
)

# An empty word list builds a '()' alternation, and that regex matches ANY text.
# Building all three regexes inside one try block meant a failure in the first
# left the others silently $null, disabling keyword scanning entirely.
function New-KeywordRegex {
    param([string[]]$Words, [switch]$WordBoundary)
    $clean = @($Words | Where-Object { $_ -and $_.Trim() })
    if ($clean.Count -eq 0) { return $null }
    $body = '(' + (($clean | ForEach-Object { [regex]::Escape($_) }) -join '|') + ')'
    if ($WordBoundary) { $body = '(?<![a-z0-9])' + $body + '(?![a-z0-9])' }
    try { return [regex]::new($body, 'IgnoreCase, CultureInvariant, Compiled') } catch { return $null }
}

$script:excludedWordsRegex = New-KeywordRegex -Words $script:excludedWords -WordBoundary

function Test-PathExcluded {
    param([string]$Path)
    if (-not $Path) { return $false }
    $p = $Path.ToString().ToLowerInvariant()

    foreach ($part in $script:excludedPathParts) {
        if ($p.Contains($part)) { return $true }
    }

    foreach ($seg in ($p -split '[\\/]')) {
        if (-not $seg) { continue }
        if ($script:excludedSegments -contains $seg) { return $true }
    }

    if ($script:excludedWordsRegex -and $script:excludedWordsRegex.IsMatch($p)) { return $true }

    return $false
}

#endregion

#region Keyword matching -----------------------------------------------------

# The Strong tier is brand names, so substring matching is safe there
# ("neverlose_loader.exe" must be caught). Medium and Weak require word
# boundaries, which is what eliminates the old main false-positive sources:
# 'loader' -> "preloader.dll", 'dma' -> "admanager.dll", 'trigger' -> "triggers.dll".
# Combined list for the Everything brand queries.
$script:kwStrongAll = @($script:kwStrongLoose + $script:kwStrongExact)

$script:kwStrongLooseRegex = New-KeywordRegex -Words $script:kwStrongLoose
$script:kwStrongExactRegex = New-KeywordRegex -Words $script:kwStrongExact -WordBoundary
$script:kwMediumRegex      = New-KeywordRegex -Words $script:kwMedium -WordBoundary
$script:kwWeakRegex        = New-KeywordRegex -Words $script:kwWeak -WordBoundary

# Returns: $null | @{ Tier = 'Strong'|'Medium'|'Weak'; Match = '<matched word>' }
function Get-CheatKeywordMatch {
    param([string]$Text)
    if (-not $Text) { return $null }
    if ($script:kwStrongLooseRegex) {
        $mm = $script:kwStrongLooseRegex.Match($Text)
        if ($mm.Success) { return @{ Tier = 'Strong'; Match = $mm.Value } }
    }
    if ($script:kwStrongExactRegex) {
        $mm = $script:kwStrongExactRegex.Match($Text)
        if ($mm.Success) { return @{ Tier = 'Strong'; Match = $mm.Value } }
    }
    if ($script:kwMediumRegex) {
        $mm = $script:kwMediumRegex.Match($Text)
        if ($mm.Success) { return @{ Tier = 'Medium'; Match = $mm.Value } }
    }
    if ($script:kwWeakRegex) {
        $mm = $script:kwWeakRegex.Match($Text)
        if ($mm.Success) { return @{ Tier = 'Weak'; Match = $mm.Value } }
    }
    return $null
}

#endregion

#region Budget / progress ----------------------------------------------------

function Test-Deadline { return ((Get-Date) -ge $script:Deadline) }

function Test-StepBudget {
    if ((Get-Date) -ge $script:StepDeadline) {
        $script:StepTimedOut = $true
        return $true
    }
    return $false
}

function Clear-ProgressLine { Write-Host ("`r" + (" " * 78) + "`r") -NoNewline }

$script:lastQuickEditRecheck = Get-Date

function Write-ScanProgressText($activity, $current, $total, $detail) {
    if (-not $total -or $total -le 0) { return }

    # Re-applying once per step is not enough for layers that spawn hundreds of
    # external processes internally (archive scanning starts a separate 7z.exe
    # per archive) - the mode can be reset while that layer is still running.
    # So this function, the one called constantly inside hot loops, re-checks
    # every 3 seconds as well.
    if (((Get-Date) - $script:lastQuickEditRecheck).TotalSeconds -ge 3) {
        $script:lastQuickEditRecheck = Get-Date
        Set-QuickEditDisabled
    }

    $pct = [math]::Min(100, [math]::Round(($current / $total) * 100))
    $pctStr = ([string]$pct).PadLeft(3, ' ')
    if ($null -eq $detail) { $detail = '' }
    $detail = [string]$detail
    if ($detail.Length -gt 30) { $detail = $detail.Substring(0, 27) + "..." }
    Write-Host "`r[ $pctStr% ] $activity : $($detail.PadRight(30, ' '))" -NoNewline -ForegroundColor Cyan
}

function End-ScanProgressText { Clear-ProgressLine }

function Write-Step($num, $total, $title) {
    $el = [math]::Round(((Get-Date) - $scanStart).TotalSeconds)
    Write-Host ''
    Write-Host "  [$num/$total] $title" -ForegroundColor Yellow
    Write-Host "  $('-'*58) (${el}s)" -ForegroundColor DarkGray
}

# Every layer runs inside its own try/catch and its own time budget. If a layer
# crashes or overruns, the scan continues and the result is flagged "partial".
function Invoke-ScanStep {
    param([int]$Number, [int]$Total, [string]$Title, [int]$BudgetSec, [scriptblock]$Action)

    if (Test-Deadline) {
        $script:partialScan = $true
        Write-Host ''
        Write-Host "  [$Number/$Total] $Title -> SKIPPED (total time limit reached)" -ForegroundColor DarkYellow
        $script:scanNotes.Add("Step $Number ($Title) was skipped because the total time limit was reached.")
        return
    }

    # Re-apply the QuickEdit fix before each step - an external process
    # (reg.exe, 7z.exe) or COM object (WScript.Shell) from the previous step may
    # have quietly reset the console mode. The cost is negligible (2 P/Invoke calls).
    Set-QuickEditDisabled

    Write-Step $Number $Total $Title
    $remaining = ($script:Deadline - (Get-Date)).TotalSeconds
    $budget = [math]::Max(5, [math]::Min($BudgetSec, $remaining))
    $script:StepDeadline = (Get-Date).AddSeconds($budget)
    $script:StepTimedOut = $false

    try {
        & $Action
    } catch {
        Clear-ProgressLine
        Write-Host "  [-] This layer errored, the scan continues: $($_.Exception.Message)" -ForegroundColor DarkYellow
        $script:scanNotes.Add("Step $Number ($Title) failed: $($_.Exception.Message)")
        $script:partialScan = $true
    }

    End-ScanProgressText
    if ($script:StepTimedOut) {
        $script:partialScan = $true
        Write-Host "  [!] This layer used up its $([math]::Round($budget))s budget, the rest was skipped." -ForegroundColor DarkYellow
        $script:scanNotes.Add("Step $Number ($Title) ran out of time budget and was only partially scanned.")
    }
}

#endregion


#region File / signature helpers ---------------------------------------------

# Every file access uses -LiteralPath. With -Path, files containing [ ] in their
# name (e.g. "setup [1].exe") are treated as wildcards and silently skipped -
# naming a cheat file with "[ ]" was enough to evade detection.

function Test-FileExists {
    param([string]$Path)
    if (-not $Path) { return $false }
    try { return [System.IO.File]::Exists($Path) } catch { return $false }
}

function Get-CachedSignature {
    param([string]$Path)
    if (-not $Path) { return $null }
    $key = $Path.ToLowerInvariant()
    if ($script:sigCache.ContainsKey($key)) { return $script:sigCache[$key] }

    $result = [pscustomobject]@{ Status = 'Unknown'; Signer = $null; IsValid = $false }
    try {
        if (Test-FileExists $Path) {
            $sig = Get-AuthenticodeSignature -LiteralPath $Path -ErrorAction SilentlyContinue
            if ($sig) {
                $signer = $null
                if ($sig.SignerCertificate) { $signer = $sig.SignerCertificate.Subject }
                $result = [pscustomobject]@{
                    Status  = [string]$sig.Status
                    Signer  = $signer
                    IsValid = ($sig.Status -eq 'Valid')
                }
            }
        }
    } catch {}
    $script:sigCache[$key] = $result
    return $result
}

function Test-TrustedSignedFile {
    param([string]$Path)
    return (Get-CachedSignature $Path).IsValid
}

function Get-FileSha256 {
    param([string]$Path)
    if (-not $Path) { return $null }
    $key = $Path.ToLowerInvariant()
    if ($script:hashCache.ContainsKey($key)) { return $script:hashCache[$key] }

    $hash = $null
    try {
        if (Test-FileExists $Path) {
            $fi = New-Object System.IO.FileInfo($Path)
            # Hashing files over 200MB adds minutes to the scan - skip them.
            if ($fi.Length -le 200MB) {
                $h = Get-FileHash -LiteralPath $Path -Algorithm SHA256 -ErrorAction SilentlyContinue
                if ($h -and $h.Hash) { $hash = $h.Hash.ToLowerInvariant() }
            }
        }
    } catch {}
    $script:hashCache[$key] = $hash
    return $hash
}

function Get-PEVersionInfo {
    param([string]$Path)
    if (-not $Path) { return $null }
    $key = $Path.ToLowerInvariant()
    if ($script:peCache.ContainsKey($key)) { return $script:peCache[$key] }

    $info = $null
    try {
        if (Test-FileExists $Path) {
            $vi = [System.Diagnostics.FileVersionInfo]::GetVersionInfo($Path)
            $info = [pscustomobject]@{
                CompanyName     = $vi.CompanyName
                ProductName     = $vi.ProductName
                FileDescription = $vi.FileDescription
                FileVersion     = $vi.FileVersion
                HasInfo         = ([bool]$vi.CompanyName -or [bool]$vi.ProductName -or [bool]$vi.FileDescription)
            }
        }
    } catch {}
    $script:peCache[$key] = $info
    return $info
}

function Test-HiddenSystemFile {
    param([string]$Path)
    try {
        if (-not (Test-FileExists $Path)) { return $false }
        $attrs = [System.IO.File]::GetAttributes($Path)
        return ((($attrs -band [System.IO.FileAttributes]::Hidden) -ne 0) -or (($attrs -band [System.IO.FileAttributes]::System) -ne 0))
    } catch { return $false }
}

function Is-ExecutableHeader {
    param([string]$Path)
    $fs = $null
    try {
        if (-not (Test-FileExists $Path)) { return $false }
        $fs = New-Object System.IO.FileStream($Path, [System.IO.FileMode]::Open, [System.IO.FileAccess]::Read, [System.IO.FileShare]::ReadWrite)
        if ($fs.Length -lt 2) { return $false }
        $b0 = $fs.ReadByte(); $b1 = $fs.ReadByte()
        return ($b0 -eq 0x4D -and $b1 -eq 0x5A)
    } catch { return $false }
    finally { if ($fs) { try { $fs.Dispose() } catch {} } }
}

# The source URL of a downloaded file lives in an NTFS alternate data stream.
# This is the direct evidence for "where did you download this exe from".
function Get-DownloadSourceUrl {
    param([string]$Path)
    try {
        $zone = Get-Content -LiteralPath $Path -Stream 'Zone.Identifier' -ErrorAction SilentlyContinue
        if (-not $zone) { return $null }
        foreach ($line in $zone) {
            if ($line -match '^(?:HostUrl|ReferrerUrl)\s*=\s*(.+)$') { return $Matches[1].Trim() }
        }
    } catch {}
    return $null
}

#endregion

#region Trusted process / masquerade -----------------------------------------

$script:trustedProcessNames = @(
    'chrome','msedge','msedgewebview2','firefox','opera','opera_gx','brave','vivaldi','yandex',
    'discord','discordptb','discordcanary','steam','steamwebhelper','steamservice','onedrive',
    'teams','ms-teams','lghub','lghub_agent','lghub_updater','razer','synapse','steelseries',
    'spotify','epicgameslauncher','epicwebhelper','nvsphelper64','nvcontainer','nvidia share',
    'nvidia web helper','battle.net','riotclientservices','vgtray','winws','goodbyedpi',
    'cmd','powershell','pwsh','conhost','svchost','explorer','taskhostw','runtimebroker',
    'dllhost','smartscreen','ctfmon','backgroundtaskhost','searchhost','searchindexer',
    'startmenuexperiencehost','shellexperiencehost','textinputhost','applicationframehost',
    'wermgr','werfault','spoolsv','lsass','services','wininit','csrss','smss','winlogon',
    'fontdrvhost','securityhealthservice','securityhealthsystray','sihost','taskmgr',
    'msmpeng','nissrv','mpdefendercoreservice','audiodg','dwm','sppsvc','wmiprvse',
    'altsnap','flow.launcher','flowlauncher','obs64','obs32','code','devenv'
)

function Test-TrustedProcessName {
    param([string]$Name)
    if (-not $Name) { return $false }
    try {
        $clean = ([System.IO.Path]::GetFileNameWithoutExtension($Name.ToString())).ToLowerInvariant()
    } catch { return $false }
    return ($script:trustedProcessNames -contains $clean)
}

# Is a trusted-looking process actually running from where it belongs?
# chrome.exe must live under \google\chrome\; a "chrome.exe" running out of the
# Downloads folder is a classic cheat masquerade.
$script:trustedPaths = @{
    'chrome'            = @('\google\chrome\', '\chromium\')
    'msedge'            = @('\microsoft\edge\')
    'msedgewebview2'    = @('\microsoft\edge','\edgewebview\')
    'firefox'           = @('\mozilla firefox\')
    'opera'             = @('\opera\')
    'opera_gx'          = @('\opera gx\','\opera\')
    'brave'             = @('\bravesoftware\')
    'vivaldi'           = @('\vivaldi\')
    'yandex'            = @('\yandex\')
    'discord'           = @('\discord')
    'steam'             = @('\steam\')
    'steamwebhelper'    = @('\steam\')
    'steamservice'      = @('\steam\')
    'onedrive'          = @('\onedrive')
    'teams'             = @('\microsoft\teams', '\teams', '\windowsapps\')
    'spotify'           = @('\spotify')
    'epicgameslauncher' = @('\epic games\')
    'nvcontainer'       = @('\nvidia')
    'nvsphelper64'      = @('\nvidia')
    'riotclientservices'= @('\riot games\')
    'winws'             = @('\winws', '\zapret')
    'goodbyedpi'        = @('\goodbyedpi')
    'flowlauncher'      = @('\flowlauncher', '\flow.launcher')
    'altsnap'           = @('\altsnap')
    'lghub'             = @('\lghub', '\logi')
    'lghub_agent'       = @('\lghub', '\logi')
    'obs64'             = @('\obs-studio\')
    'code'              = @('\microsoft vs code','\vscode')
}

function Test-TrustedProcessPath {
    param([string]$ProcessName, [string]$ProcessPath)
    if (-not $ProcessName -or -not $ProcessPath) { return $true }
    try {
        $name = ([System.IO.Path]::GetFileNameWithoutExtension($ProcessName.ToString())).ToLowerInvariant()
    } catch { return $true }
    $lp = $ProcessPath.ToString().ToLowerInvariant()

    if ($lp -like '*\windows\system32\*' -or $lp -like '*\windows\syswow64\*' -or $lp -like '*\windows\explorer.exe') { return $true }
    if (-not $script:trustedPaths.ContainsKey($name)) { return $true }
    foreach ($ed in $script:trustedPaths[$name]) {
        if ($lp.Contains($ed)) { return $true }
    }
    return $false
}

$script:expectedPublishers = @{
    'chrome' = @('google'); 'msedge' = @('microsoft'); 'msedgewebview2' = @('microsoft')
    'firefox' = @('mozilla'); 'opera' = @('opera'); 'brave' = @('brave')
    'discord' = @('discord'); 'steam' = @('valve'); 'steamwebhelper' = @('valve')
    'spotify' = @('spotify'); 'epicgameslauncher' = @('epic games')
    'riotclientservices' = @('riot games'); 'nvcontainer' = @('nvidia')
}

function Test-SignaturePublisher {
    param([string]$FilePath, [string]$ProcessName)
    if (-not $FilePath -or -not $ProcessName) { return $true }
    try {
        $name = ([System.IO.Path]::GetFileNameWithoutExtension($ProcessName.ToString())).ToLowerInvariant()
    } catch { return $true }
    if (-not $script:expectedPublishers.ContainsKey($name)) { return $true }

    $sig = Get-CachedSignature $FilePath
    if (-not $sig.IsValid) { return $false }
    if (-not $sig.Signer) { return $false }
    $signerLower = $sig.Signer.ToLowerInvariant()
    foreach ($pub in $script:expectedPublishers[$name]) {
        if ($signerLower.Contains($pub)) { return $true }
    }
    return $false
}

$script:genericMasqueradeNames = @(
    'update','updater','helper','service','runtime','host','setup','installer','launcher',
    'client','driver','agent','worker','daemon','monitor','manager','system','security',
    'defender','config','settings','tool','utility','app','run','start','init','load',
    'data','sync','task','svchst','csrs','lsas','explrer','wininl','scvhost','svch0st'
)

function Test-GenericMasqueradeName {
    param([string]$FileName)
    if (-not $FileName) { return $false }
    try {
        $base = ([System.IO.Path]::GetFileNameWithoutExtension($FileName.ToString())).ToLowerInvariant()
    } catch { return $false }
    return ($script:genericMasqueradeNames -contains $base)
}

#endregion

#region Name entropy / random-name scoring -----------------------------------

function Get-NameEntropy {
    param([string]$Name)
    if (-not $Name) { return 0 }
    try { $s = ([System.IO.Path]::GetFileNameWithoutExtension($Name.ToString())).ToLowerInvariant() } catch { return 0 }
    if ($s.Length -eq 0) { return 0 }
    $counts = @{}
    foreach ($ch in $s.ToCharArray()) {
        $k = [string]$ch
        if (-not $counts.ContainsKey($k)) { $counts[$k] = 0 }
        $counts[$k]++
    }
    $entropy = 0.0
    foreach ($v in $counts.Values) {
        $p = [double]$v / [double]$s.Length
        if ($p -gt 0) { $entropy -= $p * ([math]::Log($p) / [math]::Log(2)) }
    }
    return [math]::Round($entropy, 2)
}

# Random/meaningless name score. With looser thresholds "app2.exe" scored 45 and,
# combined with "created in the last 30 days", produced a HIGH finding. Scoring
# is now stricter and common version/language patterns are whitelisted.
$script:commonWordRegex = [regex]::new('(setup|update|install|launch|serv|help|client|host|driver|agent|core|main|lib|api|net|sql|node|python|java|win|x64|x86|v[0-9]|20[0-9]{2}|steam|discord|chrome|edge|nvidia|amd|intel|micro|unity|unreal|game|mod|patch|tool|data|cache|log|temp|test|demo|full|free|pro|plus)', 'IgnoreCase, CultureInvariant, Compiled')

function Get-RandomNameScore {
    param([string]$FileName)
    $empty = [pscustomobject]@{ Score = 0; Entropy = 0; Reasons = '' }
    if (-not $FileName) { return $empty }
    try { $base = ([System.IO.Path]::GetFileNameWithoutExtension($FileName.ToString())).ToLowerInvariant() } catch { return $empty }
    if (-not $base -or (Test-TrustedProcessName $base)) { return $empty }

    $score = 0
    $reasons = @()

    # Pure hex name (a3f9b21c.exe) or epoch-like numeric name: injector/loader pattern.
    if ($base -match '^[a-f0-9]{8,32}$') { $score += 45; $reasons += 'HexOnlyName' }
    elseif ($base -match '^\d{9,13}$')   { $score += 35; $reasons += 'EpochLikeName' }

    $letters = ([regex]::Matches($base, '[a-z]')).Count
    $digits  = ([regex]::Matches($base, '\d')).Count
    if ($base.Length -gt 0) {
        $digitRatio = [double]$digits / [double]$base.Length
        $vowels = ([regex]::Matches($base, '[aeiou]')).Count
        $vowelRatio = if ($letters -gt 0) { [double]$vowels / [double]$letters } else { 1 }
        if ($digitRatio -ge 0.35) { $score += 20; $reasons += 'HighDigitRatio' }
        if ($letters -ge 6 -and $vowelRatio -lt 0.18) { $score += 25; $reasons += 'NoVowels' }
    }

    $entropy = Get-NameEntropy $base
    if ($entropy -ge 3.3) { $score += 25; $reasons += "Entropy=$entropy" }
    elseif ($entropy -ge 3.0) { $score += 12; $reasons += "Entropy=$entropy" }

    # Containing a known word fragment makes randomness far less likely.
    if ($script:commonWordRegex.IsMatch($base)) { $score -= 35; $reasons += 'ContainsCommonWord' }
    if ($base.Length -le 4) { $score -= 20; $reasons += 'ShortName' }

    if ($score -lt 0) { $score = 0 }
    return [pscustomobject]@{
        Score   = [math]::Min(100, $score)
        Entropy = $entropy
        Reasons = ($reasons -join '+')
    }
}

function Test-SuspiciousPath {
    param([string]$Path)
    if (-not $Path) { return $false }
    $p = $Path.ToString().ToLowerInvariant()
    if ($p -like '*\temp\*' -or $p -like '*\downloads\*' -or $p -like '*\appdata\local\temp\*') { return $true }
    if ($p -like '*\desktop\*' -or $p -like '*\documents\*') { return $true }
    if ($p -like '*\appdata\roaming\*' -or $p -like '*\appdata\local\*') { return $true }
    if ($p -like '*\programdata\*') { return $true }
    if ($p -like '*\$recycle.bin\*') { return $true }
    return $false
}

function Test-TrustedModulePath {
    param([string]$Path)
    if (-not $Path) { return $false }
    $p = $Path.ToString().ToLowerInvariant()
    return ($p -like '*\windows\*' -or $p -like '*\program files\*' -or $p -like '*\program files (x86)\*' -or
            $p -like '*\steam\*' -or $p -like '*\steamapps\*' -or $p -like '*\nvidia*' -or
            $p -like '*\amd\*' -or $p -like '*\intel\*')
}

#endregion

#region Finding recording ----------------------------------------------------

# LOW and INFO contribute NOTHING to the verdict; they are context for the
# admin only. In smoke testing 310 LOW findings pushed the risk score to 360 and
# flagged a clean machine as "CHEAT DETECTED". The verdict uses HIGH/MEDIUM only.
$script:riskWeights = @{ 'HIGH' = 10; 'MEDIUM' = 3; 'LOW' = 0; 'INFO' = 0 }

# Per-category cap so a single category cannot drown out the report.
$script:categoryCounts = @{}
$script:MaxPerCategory = 25

# Findings are de-duplicated. Without this the same file was reported three
# times - once by the Everything sweep, once by the risky-path sweep and once by
# the Downloads sweep - inflating the count into scary numbers like "43 findings".
function Add-Finding {
    param(
        [string]$Category,
        [string]$Message,
        [string]$Path = $null,
        [ValidateSet('HIGH','MEDIUM','LOW','INFO')][string]$Risk = 'MEDIUM',
        [switch]$Confirmed,
        [switch]$NoHash
    )

    $dedupeKey = ($Category + '|' + $(if ($Path) { $Path.ToLowerInvariant() } else { $Message.ToLowerInvariant() }))
    if ($script:seenFindings.ContainsKey($dedupeKey)) { return }
    $script:seenFindings[$dedupeKey] = $true

    # Category cap: conclusive findings are always recorded.
    if (-not $Confirmed) {
        if (-not $script:categoryCounts.ContainsKey($Category)) { $script:categoryCounts[$Category] = 0 }
        $script:categoryCounts[$Category]++
        if ($script:categoryCounts[$Category] -eq ($script:MaxPerCategory + 1)) {
            $script:scanNotes.Add("Category '$Category' has more than $($script:MaxPerCategory) findings; the report was truncated.")
        }
        if ($script:categoryCounts[$Category] -gt $script:MaxPerCategory) { return }
    }

    $hashStr = ''
    if ($Path -and -not $NoHash) {
        $hash = Get-FileSha256 $Path
        if ($hash) { $hashStr = " [SHA256: $hash]" }
    }

    $script:riskScore += $script:riskWeights[$Risk]
    if ($Confirmed) { $script:confirmedCheat = $true }

    $line = "[$Risk] ${Category}: $Message$hashStr"
    $script:findings.Add($line)

    Clear-ProgressLine
    $color = switch ($Risk) { 'HIGH' { 'Red' } 'MEDIUM' { 'Yellow' } 'LOW' { 'DarkYellow' } default { 'Gray' } }
    Write-Host "  [!] [$Risk] $Category -> $Message$hashStr" -ForegroundColor $color

    if ($script:logWriter) { try { $script:logWriter.WriteLine("- $line") } catch {} }
}

#endregion

#region Bounded directory walker ---------------------------------------------

# The single biggest performance problem in the naive approach: one
# "Get-ChildItem -Recurse -Depth 3" call per cheat pattern. 76 folder patterns x
# 130 file patterns x each disk = walking the same tree hundreds of times. The
# tree is now walked ONCE and all patterns are matched in memory.
function Get-FilesBounded {
    param(
        [string]$Root,
        [int]$MaxDepth = 3,
        [int]$MaxFiles = 20000,
        [string[]]$Extensions = $null
    )

    $result = New-Object System.Collections.Generic.List[System.IO.FileInfo]
    if (-not $Root) { return $result }
    try { if (-not [System.IO.Directory]::Exists($Root)) { return $result } } catch { return $result }

    $extSet = $null
    if ($Extensions) {
        $extSet = New-Object 'System.Collections.Generic.HashSet[string]' ([System.StringComparer]::OrdinalIgnoreCase)
        foreach ($e in $Extensions) { [void]$extSet.Add($e) }
    }

    $queue = New-Object System.Collections.Generic.Queue[object]
    $queue.Enqueue(@{ Path = $Root; Depth = 0 })
    $count = 0
    $dirsWalked = 0

    while ($queue.Count -gt 0) {
        if ($count -ge $MaxFiles) { break }
        # Budget check every 64 directories: calling Get-Date every iteration is expensive.
        if (($dirsWalked % 64) -eq 0 -and (Test-StepBudget)) { break }
        $dirsWalked++

        $node = $queue.Dequeue()
        $dir = $node.Path
        $depth = $node.Depth

        try {
            $di = New-Object System.IO.DirectoryInfo($dir)
            foreach ($f in $di.EnumerateFiles()) {
                if ($extSet -and -not $extSet.Contains($f.Extension)) { continue }
                $result.Add($f)
                $count++
                if ($count -ge $MaxFiles) { break }
            }
        } catch { continue }

        if ($depth -ge $MaxDepth) { continue }

        try {
            $di = New-Object System.IO.DirectoryInfo($dir)
            foreach ($sub in $di.EnumerateDirectories()) {
                try {
                    if (($sub.Attributes -band [System.IO.FileAttributes]::ReparsePoint) -ne 0) { continue }
                } catch { continue }
                if (Test-PathExcluded $sub.FullName) { continue }
                $queue.Enqueue(@{ Path = $sub.FullName; Depth = $depth + 1 })
            }
        } catch { continue }
    }

    return $result
}

function Get-UserPath($name) { try { return [Environment]::GetFolderPath($name) } catch { return $null } }

#endregion

#region Everything CLI -------------------------------------------------------

$script:es           = $null
$script:esAvailable  = $false
$script:scanCoverage = 'native'

# CRITICAL: es.exe alone is NOT enough - the Everything application/service must
# be running, because es.exe queries it over IPC. Skipping this check meant
# es.exe was downloaded, "install successful" was printed, and on machines
# without Everything the whole-disk sweep (the widest layer) silently returned
# nothing. The scan came back "clean" and finished early - one of the main
# causes of "sometimes it closes really fast".
function Test-EverythingAvailable {
    param([string]$EsPath)
    if (-not $EsPath) { return $false }
    try {
        $null = & $EsPath -get-everything-version 2>$null
        if ($LASTEXITCODE -eq 0) { return $true }
        # Some ES builds have no -get-everything-version; verify with a simple query.
        $null = & $EsPath -n 1 -no-result-error "ext:exe" 2>$null
        return ($LASTEXITCODE -eq 0)
    } catch { return $false }
}

function Initialize-EverythingCli {
    $esDir = Join-Path (Get-UserPath "Desktop") "cheat check"
    $esExePath = Join-Path $esDir "es.exe"

    if (Test-FileExists $esExePath) {
        $script:es = $esExePath
    } else {
        $cmd = Get-Command "es.exe" -ErrorAction SilentlyContinue
        if ($cmd) { $script:es = $cmd.Source }
    }

    if (-not $script:es) {
        Write-Host "  [*] Everything CLI not found, downloading..." -ForegroundColor Cyan
        try {
            if (-not (Test-Path -LiteralPath $esDir)) { New-Item -ItemType Directory -Path $esDir -Force | Out-Null }
            $dlPath = Join-Path (Get-UserPath "UserProfile") "Downloads"
            $foundZip = $null
            foreach ($z in @("ES-1.1.0.29.x64.zip", "ES-1.1.0.30.x64.zip")) {
                $cand = Join-Path $dlPath $z
                if (Test-FileExists $cand) { $foundZip = $cand; break }
            }
            if (-not $foundZip) {
                $foundZip = Join-Path $esDir "ES-1.1.0.30.x64.zip"
                Invoke-WebRequest -Uri 'https://www.voidtools.com/ES-1.1.0.30.x64.zip' -OutFile $foundZip -UseBasicParsing -TimeoutSec 45 -ErrorAction SilentlyContinue
            }
            if (Test-FileExists $foundZip) {
                Expand-Archive -LiteralPath $foundZip -DestinationPath $esDir -Force -ErrorAction SilentlyContinue
            }
            if (Test-FileExists $esExePath) { $script:es = $esExePath }
        } catch {}
    }

    if ($script:es) {
        $script:esAvailable = Test-EverythingAvailable $script:es
    }

    if ($script:esAvailable) {
        $script:scanCoverage = 'everything'
        Write-Host "  [+] Everything index available - whole-disk scanning enabled." -ForegroundColor Green
    } else {
        $script:scanCoverage = 'native'
        if ($script:es) {
            Write-Host "  [!] es.exe is present but the Everything application is not running." -ForegroundColor DarkYellow
        } else {
            Write-Host "  [!] Everything CLI could not be installed." -ForegroundColor DarkYellow
        }
        Write-Host "      Falling back to the built-in file system walker (slightly narrower)." -ForegroundColor DarkYellow
        # Aborting the whole scan here (and closing after 3 seconds) was the old
        # behaviour. The scan now continues with the built-in walker and simply
        # records a note.
        $script:scanNotes.Add("Everything index unavailable; disk scanning ran with the built-in walker at reduced coverage.")
    }
}

function Invoke-EsQuery {
    param([string]$Query, [int]$Limit = 0)
    $out = @()
    if (-not $script:esAvailable) { return $out }
    try {
        # The query must be passed as ONE quoted argument. Written as
        # `& $es ext:exe;dll -no-result-error`, PowerShell treats ';' as a
        # statement separator: the command runs as `es.exe ext:exe` and then
        # PowerShell looks for a command named "dll" and errors. Net effect:
        # .dll files were never scanned at all.
        if ($Limit -gt 0) {
            $out = & $script:es -no-result-error -n $Limit $Query 2>$null
        } else {
            $out = & $script:es -no-result-error $Query 2>$null
        }
        if ($LASTEXITCODE -ne 0) { return @() }
    } catch { return @() }
    if (-not $out) { return @() }
    return @($out)
}

#endregion

#region Native memory API ----------------------------------------------------

function Initialize-MemoryApi {
    if ('ScannerNative.MemoryApi' -as [type]) { return $true }
    try {
        Add-Type -TypeDefinition @"
using System;
using System.Runtime.InteropServices;
namespace ScannerNative {
    public static class MemoryApi {
        [DllImport("kernel32.dll", SetLastError=true)] public static extern IntPtr OpenProcess(UInt32 access, bool inherit, UInt32 pid);
        [DllImport("kernel32.dll", SetLastError=true)] public static extern bool CloseHandle(IntPtr h);
        [DllImport("kernel32.dll", SetLastError=true)] public static extern IntPtr VirtualQueryEx(IntPtr h, IntPtr address, out MEMORY_BASIC_INFORMATION64 info, UInt32 length);
    }
    [StructLayout(LayoutKind.Sequential)]
    public struct MEMORY_BASIC_INFORMATION64 {
        public UInt64 BaseAddress;
        public UInt64 AllocationBase;
        public UInt32 AllocationProtect;
        public UInt32 __alignment1;
        public UInt64 RegionSize;
        public UInt32 State;
        public UInt32 Protect;
        public UInt32 Type;
        public UInt32 __alignment2;
    }
}
"@ -ErrorAction SilentlyContinue
        return ('ScannerNative.MemoryApi' -as [type]) -ne $null
    } catch { return $false }
}

# A MEM_PRIVATE + PAGE_EXECUTE_* region inside a game process is executable
# memory with no module backing it = manual-mapped / injected code. This catches
# it even when the DLL was never written to disk.
function Scan-ExecutablePrivateMemory {
    param($Process)
    if (-not $Process -or -not (Initialize-MemoryApi)) { return }
    $handle = [ScannerNative.MemoryApi]::OpenProcess(0x0400 -bor 0x0010, $false, [uint32]$Process.Id)
    if ($handle -eq [IntPtr]::Zero) { return }
    try {
        $addr = [UInt64]0
        $maxAddr = if ([IntPtr]::Size -eq 8) { [UInt64]0x00007fffffffffff } else { [UInt64]0xffffffff }
        $count = 0
        $hits = 0
        $structSize = [System.Runtime.InteropServices.Marshal]::SizeOf([type][ScannerNative.MEMORY_BASIC_INFORMATION64])
        while ($addr -lt $maxAddr -and $count -lt 200000 -and $hits -lt 25) {
            if (($count % 4096) -eq 0 -and (Test-StepBudget)) { break }
            $mbi = New-Object ScannerNative.MEMORY_BASIC_INFORMATION64
            $ret = [ScannerNative.MemoryApi]::VirtualQueryEx($handle, [IntPtr]$addr, [ref]$mbi, $structSize)
            if ($ret -eq [IntPtr]::Zero -or $mbi.RegionSize -eq 0) { break }

            $isExec = (($mbi.Protect -band 0x10) -ne 0 -or ($mbi.Protect -band 0x20) -ne 0 -or
                       ($mbi.Protect -band 0x40) -ne 0 -or ($mbi.Protect -band 0x80) -ne 0)
            # 0x1000 = MEM_COMMIT, 0x20000 = MEM_PRIVATE
            if ($mbi.State -eq 0x1000 -and $mbi.Type -eq 0x20000 -and $isExec -and $mbi.RegionSize -ge 8192) {
                $hits++
                Add-Finding 'ExecutablePrivateMemory' `
                    "Process:$($Process.ProcessName) PID:$($Process.Id) Base:0x$('{0:X}' -f $mbi.BaseAddress) SizeKB:$([math]::Round($mbi.RegionSize / 1KB, 1)) Protect:0x$('{0:X}' -f $mbi.Protect) (executable memory with no module backing it = manual-map/injection indicator)" `
                    $null 'HIGH'
            }

            $next = $mbi.BaseAddress + $mbi.RegionSize
            if ($next -le $addr) { break }
            $addr = $next
            $count++
        }
    } catch {} finally {
        [void][ScannerNative.MemoryApi]::CloseHandle($handle)
    }
}

#endregion

#region Verdict + reporting --------------------------------------------------

function Get-ScanVerdict {
    # A single MEDIUM finding used to set status='cheat', so any unsigned
    # portable program flagged the player as "CHEAT DETECTED". The verdict now
    # uses a weighted score plus a separate "conclusive finding" concept.
    # LOW findings carry no weight. 'cheat' requires either a conclusive
    # (Confirmed) finding or roughly four independent HIGH findings.
    if ($script:confirmedCheat) { return 'cheat' }
    if ($script:riskScore -ge 40) { return 'cheat' }
    if ($script:riskScore -ge 9)  { return 'suspicious' }
    return 'clean'
}

function Send-PanelResults {
    param(
        [string]$LogPath,
        [double]$Duration,
        [string]$StatusOverride = $null
    )

    $panelUrl = "%%PANEL_URL%%"
    $apiKey   = "%%API_KEY%%"
    $token    = "%%SCAN_TOKEN%%"
    if (-not $panelUrl -or -not $apiKey -or -not $token) { return $false }

    $status = if ($StatusOverride) { $StatusOverride } else { Get-ScanVerdict }

    $rawLog = ""
    if ($LogPath -and (Test-FileExists $LogPath)) {
        try {
            $rawLog = [System.IO.File]::ReadAllText($LogPath)
            if ($rawLog.Length -gt 250000) {
                $rawLog = $rawLog.Substring(0, 250000) + "`n... (log too large, truncated)"
            }
        } catch {}
    }

    $highCount = 0; $mediumCount = 0
    $findingsToSend = New-Object System.Collections.Generic.List[string]
    foreach ($f in $script:findings) {
        if ($f -like '`[HIGH`]*')   { $highCount++ }
        if ($f -like '`[MEDIUM`]*') { $mediumCount++ }
        if ($findingsToSend.Count -lt 300) {
            $findingsToSend.Add($f.Substring(0, [math]::Min(900, $f.Length)))
        }
    }

    $body = @{
        token         = $token
        status        = $status
        finding_count = $script:findings.Count
        scan_duration = [math]::Round($Duration, 1)
        findings      = [string[]]$findingsToSend.ToArray()
        computer_name = $env:COMPUTERNAME
        username      = $env:USERNAME
        raw_log       = $rawLog
        risk_score    = $script:riskScore
        high_count    = $highCount
        medium_count  = $mediumCount
        scan_coverage = $script:scanCoverage
        partial       = [bool]$script:partialScan
        elevated      = [bool]$script:isAdmin
    } | ConvertTo-Json -Depth 4

    $headers = @{ "X-API-Key" = $apiKey }

    for ($attempt = 1; $attempt -le 4; $attempt++) {
        try {
            $response = Invoke-RestMethod -Uri "$panelUrl/api/cheat-check/results" -Method Post -Headers $headers `
                -Body ([System.Text.Encoding]::UTF8.GetBytes($body)) -ContentType "application/json; charset=utf-8" `
                -TimeoutSec 45 -ErrorAction Stop
            return ($null -ne $response.data)
        } catch {
            $statusCode = $null
            if ($_.Exception.Response) { try { $statusCode = [int]$_.Exception.Response.StatusCode } catch {} }

            if ($statusCode -eq 409) {
                Write-Host "  [-] This scan already has a result, not sending again." -ForegroundColor Yellow
                return $false
            }
            if ($statusCode -eq 401 -or $statusCode -eq 404) {
                Write-Host "  [-] The panel rejected the request (HTTP $statusCode). The token may be invalid or expired." -ForegroundColor Red
                return $false
            }

            Write-Host "  [-] Upload failed (attempt $attempt/4): $($_.Exception.Message)" -ForegroundColor Red
            if ($attempt -lt 4) { Start-Sleep -Seconds ([math]::Min(5 * $attempt, 15)) }
        }
    }
    return $false
}

#endregion

#region Banner ---------------------------------------------------------------

Write-Host ''
Write-Host "   ____ _   _ _____ _  _____    ____ _   _ _____ ____ _  __" -ForegroundColor Magenta
Write-Host "  / ___| | | | ____| ||_   _|  / ___| | | | ____/ ___| |/ /" -ForegroundColor Magenta
Write-Host " | |   | |_| |  _| | |  | |   | |   | |_| |  _|| |   | ' / " -ForegroundColor Magenta
Write-Host " | |___|  _  | |___| |  | |   | |___|  _  | |__| |___| . \ " -ForegroundColor Magenta
Write-Host "  \____|_| |_|_____|_|  |_|    \____|_| |_|_____\____|_|\_\" -ForegroundColor Magenta
Write-Host "===========================================================" -ForegroundColor DarkGray
Write-Host "  CHEAT CHECK SCANNER v3.0" -ForegroundColor Yellow
Write-Host "===========================================================" -ForegroundColor DarkGray
Write-Host ''
if (-not $script:isAdmin) {
    Write-Host "  [!] LIMITED MODE: driver, memory and Amcache layers will be skipped." -ForegroundColor DarkYellow
    $script:scanNotes.Add("The scan ran WITHOUT administrator rights; driver/memory/Amcache layers are missing.")
    $script:partialScan = $true
    Write-Host ''
}

Initialize-EverythingCli

Write-Host ''
Write-Host "  [*] Starting the scan (time limit: $($script:TotalBudgetSec)s)..." -ForegroundColor Green

$totalSteps = 20

#endregion


$script:unsignedProcessPids = @{}
$script:gameNames = @('cs2','csgo','valorant','rust','r5apex','fortnite','gta5','fivem','plutonium','hl2','left4dead2','sot','apex','pubg','dayz','escapefromtarkov','eft')

# ===========================================================================
Invoke-ScanStep 1 $totalSteps 'Scanning running processes...' 120 {

    $running = @(Get-Process -ErrorAction SilentlyContinue)
    Write-Host "  [*] Inspecting $($running.Count) processes..." -ForegroundColor Gray

    $idx = 0
    foreach ($pr in $running) {
        $idx++
        if (($idx % 8) -eq 0) {
            Write-ScanProgressText 'Processes' $idx $running.Count $pr.ProcessName
            if (Test-StepBudget) { break }
        }

        $prName = $pr.ProcessName
        $pPath = $null
        try { $pPath = $pr.Path } catch {}

        # --- A. Cheat brand in the process NAME ---
        $kw = Get-CheatKeywordMatch $prName
        if ($kw -and $kw.Tier -ne 'Weak') {
            if (-not ($pPath -and (Test-PathExcluded $pPath))) {
                $risk = if ($kw.Tier -eq 'Strong') { 'HIGH' } else { 'MEDIUM' }
                $conf = ($kw.Tier -eq 'Strong')
                $detail = "PID:$($pr.Id) $prName (match: $($kw.Match))"
                if ($pPath) { $detail += " Path:$pPath" }
                if ($conf) {
                    Add-Finding 'KnownCheatProcess' $detail $pPath 'HIGH' -Confirmed
                } else {
                    Add-Finding 'SuspiciousProcessName' $detail $pPath $risk
                }
                continue
            }
        }

        # Analysis / memory editing tools: not proof of cheating, but they
        # should not be open during a check. Note that with 'cheat engine' on
        # the exclusion list, Cheat Engine itself was never reported at all.
        if ($prName -match '^(cheatengine|processhacker|systeminformer|x64dbg|x32dbg|ollydbg|ida64|ida|scylla|hxd|reclass)') {
            Add-Finding 'AnalysisTool' "PID:$($pr.Id) $prName (memory/debugging tool open during the check)" $pPath 'MEDIUM'
            continue
        }

        if (-not $pPath) { continue }
        if (Test-PathExcluded $pPath) { continue }

        # --- B. Trusted name, wrong location (masquerade) ---
        if (Test-TrustedProcessName $prName) {
            if (-not (Test-TrustedProcessPath $prName $pPath)) {
                $sig = Get-CachedSignature $pPath
                if (-not $sig.IsValid) {
                    $pe = Get-PEVersionInfo $pPath
                    $peStr = if ($pe -and -not $pe.HasInfo) { ' [NO PE info]' } elseif ($pe) { " [PE: $($pe.CompanyName)]" } else { '' }
                    Add-Finding 'MasqueradedProcess' "'$prName' uses a trusted name but runs from the WRONG LOCATION -> $pPath$peStr (PID:$($pr.Id))" $pPath 'HIGH'
                    $script:unsignedProcessPids[$pr.Id] = $pPath
                } elseif (-not (Test-SignaturePublisher $pPath $prName)) {
                    Add-Finding 'WrongPublisherSignature' "'$prName' is signed but by the WRONG publisher -> $pPath | Signer: $($sig.Signer)" $pPath 'HIGH'
                }
            }
            continue
        }

        # --- C. Generic masquerade name (update.exe, helper.exe) in a risky location ---
        if ((Test-GenericMasqueradeName $prName) -and (Test-SuspiciousPath $pPath)) {
            $sig = Get-CachedSignature $pPath
            if (-not $sig.IsValid) {
                $pe = Get-PEVersionInfo $pPath
                $peStr = if ($pe -and -not $pe.HasInfo) { ' [NO PE info]' } else { '' }
                Add-Finding 'GenericMasquerade' "'$prName' runs unsigned from a suspicious location -> $pPath$peStr (PID:$($pr.Id))" $pPath 'HIGH'
                $script:unsignedProcessPids[$pr.Id] = $pPath
            }
            continue
        }

        # --- D. Unsigned process outside system/game directories ---
        $lowerPPath = $pPath.ToLowerInvariant()
        $isSystemOrGame = $false
        foreach ($pat in @('\windows\','\program files\','\program files (x86)\','\steam','\epic games','\riot games','\origin','\electronic arts','\ubisoft','\gog galaxy','\nvidia','\amd\','\intel\')) {
            if ($lowerPPath.Contains($pat)) { $isSystemOrGame = $true; break }
        }
        if ($isSystemOrGame) { continue }

        $sig = Get-CachedSignature $pPath
        if ($sig.IsValid) { continue }

        # Being unsigned is NOT a finding on its own - most portable and open
        # source programs are unsigned. At least one supporting signal is required.
        $pe = Get-PEVersionInfo $pPath
        $noPe = [bool]($pe -and -not $pe.HasInfo)
        $rn = Get-RandomNameScore $prName
        $suspPath = Test-SuspiciousPath $pPath
        $weakKw = ($kw -and $kw.Tier -eq 'Weak')

        $signals = @()
        if ($noPe)          { $signals += 'no_pe_info' }
        if ($rn.Score -ge 55) { $signals += "random_name($($rn.Score))" }
        if ($suspPath)      { $signals += 'risky_location' }
        if ($weakKw)        { $signals += "keyword($($kw.Match))" }

        if ($signals.Count -ge 2) {
            $risk = if ($signals.Count -ge 3 -or ($noPe -and $rn.Score -ge 55)) { 'HIGH' } else { 'MEDIUM' }
            Add-Finding 'UnsignedProcess' "$prName (PID:$($pr.Id)) -> $pPath [signals: $($signals -join ', ')]" $pPath $risk
            $script:unsignedProcessPids[$pr.Id] = $pPath
        }
    }
}

# ===========================================================================
Invoke-ScanStep 2 $totalSteps 'Scanning game processes for injection...' 150 {

    $running = @(Get-Process -ErrorAction SilentlyContinue)
    $targets = @($running | Where-Object {
        ($script:gameNames -contains $_.ProcessName.ToLowerInvariant()) -or $script:unsignedProcessPids.ContainsKey($_.Id)
    })

    if ($targets.Count -eq 0) {
        Write-Host "  [*] No game process running - CS2 must be open for the injection scan." -ForegroundColor DarkYellow
        $script:scanNotes.Add("CS2/the game was not running during the scan; live injection scanning was not possible.")
        return
    }

    $scannedModules = @{}
    foreach ($pr in $targets) {
        if (Test-StepBudget) { break }
        $prName = $pr.ProcessName.ToLowerInvariant()
        $isGame = $script:gameNames -contains $prName
        Write-Host "  [*] Inspecting: $($pr.ProcessName) (PID:$($pr.Id))" -ForegroundColor Gray

        # --- Loaded module analysis ---
        try {
            foreach ($mod in $pr.Modules) {
                if (Test-StepBudget) { break }
                $mPath = $mod.FileName
                if (-not $mPath) { continue }
                $mKey = $mPath.ToLowerInvariant()
                if ($scannedModules.ContainsKey($mKey)) { continue }
                $scannedModules[$mKey] = $true
                if (Test-PathExcluded $mPath) { continue }

                $mkw = Get-CheatKeywordMatch $mod.ModuleName
                if ($mkw -and $mkw.Tier -eq 'Strong' -and -not (Test-TrustedModulePath $mPath)) {
                    Add-Finding 'CheatModule' "Loaded into $($pr.ProcessName): $mPath (match: $($mkw.Match))" $mPath 'HIGH' -Confirmed
                    continue
                }

                if (Test-TrustedModulePath $mPath) { continue }

                $signed = Test-TrustedSignedFile $mPath
                $suspPath = Test-SuspiciousPath $mPath
                $rn = Get-RandomNameScore $mod.ModuleName

                if (-not $signed -and ($isGame -or $suspPath -or $rn.Score -ge 55)) {
                    $risk = if ($isGame) { 'HIGH' } else { 'MEDIUM' }
                    $conf = ($prName -eq 'cs2' -and $suspPath)
                    $msg = "Unsigned DLL loaded into $($pr.ProcessName) -> $mPath (risky_location=$suspPath; random_name=$($rn.Score))"
                    if ($conf) { Add-Finding 'InjectedGameModule' $msg $mPath 'HIGH' -Confirmed }
                    else { Add-Finding 'InjectedGameModule' $msg $mPath $risk }
                }
            }
        } catch {}

        if (-not $isGame) { continue }

        # --- Threads with no module backing them (manual map) ---
        try {
            $modRanges = @()
            foreach ($mod in $pr.Modules) {
                $modRanges += @{ Base = $mod.BaseAddress.ToInt64(); End = $mod.BaseAddress.ToInt64() + $mod.ModuleMemorySize }
            }
            $threadHits = 0
            foreach ($th in $pr.Threads) {
                if ($threadHits -ge 10) { break }
                $startAddr = 0
                try { $startAddr = $th.StartAddress.ToInt64() } catch { continue }
                if ($startAddr -eq 0) { continue }
                $inModule = $false
                foreach ($r in $modRanges) {
                    if ($startAddr -ge $r.Base -and $startAddr -lt $r.End) { $inModule = $true; break }
                }
                if (-not $inModule) {
                    $threadHits++
                    Add-Finding 'UnbackedThread' "Process:$($pr.ProcessName) PID:$($pr.Id) ThreadID:$($th.Id) Address:0x$('{0:X}' -f $startAddr) (thread belonging to no DLL module = manual-map indicator)" $null 'HIGH'
                }
            }
        } catch {}

        # --- Executable memory regions with no module backing ---
        if ($script:isAdmin) { Scan-ExecutablePrivateMemory $pr }
    }
}

# ===========================================================================
$script:kdmapperDrivers = @('iqvw64e.sys','dbutil_2_3.sys','echo_driver.sys','capcom.sys','piddrv64.sys','piddrv.sys','nvflsh64.sys','amifldrv64.sys','segwindrvx64.sys','rwdrv.sys','physmem.sys','phymem.sys','gdrv.sys','asupio.sys','glckio2.sys')
$script:abusableDrivers = @('rtcore64.sys','winring0x64.sys','winring0.sys','asrdrv101.sys','asrdrv102.sys','asrdrv103.sys','asrdrv104.sys','atszio.sys','atszio64.sys','eneio64.sys','enetechio64.sys','msio64.sys','msio32.sys','speedfan.sys','cpuz141.sys','cpuz149.sys','nvoclock.sys','directio64.sys','hwinfo64a.sys')

Invoke-ScanStep 3 $totalSteps 'Scanning kernel drivers...' 120 {

    if (-not $script:isAdmin) {
        Write-Host "  [!] No administrator rights, driver scan skipped." -ForegroundColor DarkYellow
        return
    }

    $drivers = @(Get-CimInstance Win32_SystemDriver -ErrorAction SilentlyContinue)
    if ($drivers.Count -eq 0) { $drivers = @(Get-WmiObject Win32_SystemDriver -ErrorAction SilentlyContinue) }
    Write-Host "  [*] Inspecting $($drivers.Count) drivers..." -ForegroundColor Gray

    $found = 0
    $idx = 0
    foreach ($d in $drivers) {
        $idx++
        if (($idx % 10) -eq 0) {
            Write-ScanProgressText 'Drivers' $idx $drivers.Count $d.Name
            if (Test-StepBudget) { break }
        }

        $dName = $d.Name
        $dPath = $d.PathName
        if ($dPath) { $dPath = $dPath -replace '^\\\?\?\\', '' -replace '^\\SystemRoot\\', "$env:SystemRoot\" }
        $fileName = ''
        if ($dPath) { try { $fileName = ([System.IO.Path]::GetFileName($dPath)).ToLowerInvariant() } catch {} }

        # --- A. Known exploitable drivers with a kdmapper/DMA signature ---
        if ($fileName -and ($script:kdmapperDrivers -contains $fileName)) {
            Add-Finding 'KernelExploitDriver' "$dName -> $dPath [$($d.State)] (known vulnerable driver used by kdmapper/manual-map)" $dPath 'HIGH' -Confirmed
            $found++
            continue
        }
        if ($fileName -and ($script:abusableDrivers -contains $fileName)) {
            Add-Finding 'AbusableDriver' "$dName -> $dPath [$($d.State)] (this driver also ships with legitimate software but is frequently abused by kernel cheats)" $dPath 'MEDIUM'
            $found++
            continue
        }

        # --- B. Cheat brand in the driver name ---
        $kw = Get-CheatKeywordMatch $dName
        if (-not $kw -and $d.DisplayName) { $kw = Get-CheatKeywordMatch $d.DisplayName }
        if ($kw -and $kw.Tier -eq 'Strong') {
            Add-Finding 'CheatDriver' "$dName ($($d.DisplayName)) [$($d.State)] -> $dPath (match: $($kw.Match))" $dPath 'HIGH' -Confirmed
            $found++
            continue
        }

        # --- C. Unsigned running driver ---
        # Name-based detection alone missed every cheat driver renamed to
        # something like "svc32.sys".
        if ($d.State -eq 'Running' -and $dPath -and (Test-FileExists $dPath)) {
            if (Test-PathExcluded $dPath) { continue }
            $sig = Get-CachedSignature $dPath
            if (-not $sig.IsValid) {
                $lowerDPath = $dPath.ToLowerInvariant()
                $inSystem = ($lowerDPath.Contains('\windows\system32\drivers\') -or $lowerDPath.Contains('\windows\system32\driverstore\'))
                $risk = if ($inSystem) { 'MEDIUM' } else { 'HIGH' }
                Add-Finding 'UnsignedDriver' "$dName -> $dPath [$($d.State)] (signature status: $($sig.Status)) - an unsigned kernel driver is running" $dPath $risk
                $found++
            }
        }
    }

    if ($found -eq 0) { Write-Host '  [*] Clean, no suspicious drivers found.' -ForegroundColor Green }
}

# ===========================================================================
Invoke-ScanStep 4 $totalSteps 'Checking system integrity and anti-forensics...' 90 {

    $found = 0

    # --- A. Test signing / DSE disabled? (the classic prerequisite for loading a kernel cheat) ---
    if ($script:isAdmin) {
        try {
            $bcd = & bcdedit /enum "{current}" 2>$null | Out-String
            if ($bcd) {
                if ($bcd -match '(?im)^\s*testsigning\s+Yes') {
                    Add-Finding 'TestSigningEnabled' "Windows test signing mode is ON (bcdedit testsigning=Yes) - allows loading unsigned kernel drivers" $null 'HIGH' -Confirmed
                    $found++
                }
                if ($bcd -match '(?im)^\s*nointegritychecks\s+Yes') {
                    Add-Finding 'IntegrityChecksDisabled' "Driver signature enforcement is disabled (nointegritychecks=Yes)" $null 'HIGH' -Confirmed
                    $found++
                }
                if ($bcd -match '(?im)^\s*debug\s+Yes') {
                    Add-Finding 'KernelDebugEnabled' "Kernel debugging mode is enabled (bcdedit debug=Yes)" $null 'MEDIUM'
                    $found++
                }
            }
        } catch {}

        try {
            $sb = Confirm-SecureBootUEFI -ErrorAction SilentlyContinue
            if ($sb -eq $false) {
                Add-Finding 'SecureBootDisabled' "UEFI Secure Boot is off (not evidence on its own, evaluate alongside other findings)" $null 'LOW'
            }
        } catch {}
    }

    # --- B. Windows Defender state and exclusion lists ---
    # The first step of a cheat install is almost always excluding the cheat
    # folder from Defender.
    try {
        $mp = Get-MpPreference -ErrorAction SilentlyContinue
        if ($mp) {
            if ($mp.DisableRealtimeMonitoring -eq $true) {
                Add-Finding 'DefenderDisabled' "Windows Defender real-time protection has been turned off" $null 'MEDIUM'
                $found++
            }
            # When running unelevated, Get-MpPreference returns the string
            # "N/A: Must be an administrator to view exclusions" instead of the
            # exclusion list; that text must not be recorded as a finding.
            $isReadableExclusion = { param($v) $v -and -not ($v -like 'N/A:*') }

            foreach ($ex in @($mp.ExclusionPath)) {
                if (-not (& $isReadableExclusion $ex)) { continue }
                $lowerEx = $ex.ToLowerInvariant()
                $risky = ($lowerEx -like '*\desktop*' -or $lowerEx -like '*\downloads*' -or $lowerEx -like '*\temp*' -or
                          $lowerEx -like '*\appdata*' -or $lowerEx -eq 'c:\' -or $lowerEx -like '*\users\*')
                $kw = Get-CheatKeywordMatch $ex
                if ($kw -and $kw.Tier -eq 'Strong') {
                    Add-Finding 'DefenderCheatExclusion' "Cheat folder on the Defender exclusion list: $ex (match: $($kw.Match))" $null 'HIGH' -Confirmed
                    $found++
                } elseif ($risky) {
                    Add-Finding 'DefenderRiskyExclusion' "A user folder is on the Defender exclusion list: $ex - a typical step in cheat installs" $null 'MEDIUM'
                    $found++
                }
            }
            foreach ($ex in @($mp.ExclusionProcess)) {
                if (-not (& $isReadableExclusion $ex)) { continue }
                Add-Finding 'DefenderProcessExclusion' "A Defender process exclusion is configured: $ex" $null 'MEDIUM'
                $found++
            }
        }
    } catch {}

    # --- C. Prefetch turned off? (evidence destruction) ---
    try {
        $pfKey = Get-ItemProperty -LiteralPath 'HKLM:\SYSTEM\CurrentControlSet\Control\Session Manager\Memory Management\PrefetchParameters' -ErrorAction SilentlyContinue
        if ($pfKey -and $null -ne $pfKey.EnablePrefetcher -and $pfKey.EnablePrefetcher -eq 0) {
            Add-Finding 'PrefetchDisabled' "Prefetch has been disabled (EnablePrefetcher=0) - destroys execution history evidence" $null 'MEDIUM'
            $found++
        }
    } catch {}

    # --- D. Prefetch folder emptied? ---
    try {
        $pfDir = "$env:SystemRoot\Prefetch"
        if (Test-Path -LiteralPath $pfDir) {
            $pfCount = @([System.IO.Directory]::EnumerateFiles($pfDir, '*.pf')).Count
            $uptimeDays = 0
            try {
                $os = Get-CimInstance Win32_OperatingSystem -ErrorAction SilentlyContinue
                if ($os) { $uptimeDays = ((Get-Date) - $os.LastBootUpTime).TotalDays }
            } catch {}
            if ($pfCount -lt 15 -and $uptimeDays -gt 0.5) {
                Add-Finding 'PrefetchCleared' "Only $pfCount entries in the Prefetch folder - execution history may have been wiped" $null 'MEDIUM'
                $found++
            }
        }
    } catch {}

    # --- E. USN journal deleted? ---
    if ($script:isAdmin) {
        try {
            $usn = & fsutil usn queryjournal C: 2>$null | Out-String
            if ($usn -and $usn -match 'Usn Journal ID' -and $usn -match '(?im)First Usn\s*:\s*0x0+\s*$') {
                Add-Finding 'UsnJournalReset' "The NTFS USN change journal has been reset - file deletion traces may have been destroyed" $null 'MEDIUM'
                $found++
            }
        } catch {}
    }

    if ($found -eq 0) { Write-Host '  [*] Clean, no problems with system integrity settings.' -ForegroundColor Green }
}

# ===========================================================================
Invoke-ScanStep 5 $totalSteps 'Scanning for hardware cheat devices...' 60 {

    # Most modern CS2 cheating runs through DMA cards (Xilinx FPGA + FT601 USB3)
    # and KMBox/Arduino based hardware aim assists; a software-only scan cannot
    # see any of it.
    $found = 0

    $dmaHardware = @(
        @{ Id = 'VEN_10EE'; Label = 'Xilinx FPGA (the core of Screamer/CaptainDMA/PCILeech DMA cards)'; Risk = 'HIGH' },
        @{ Id = 'VEN_1172'; Label = 'Altera/Intel FPGA (possible DMA card)'; Risk = 'MEDIUM' },
        @{ Id = 'VID_0403&PID_601F'; Label = 'FTDI FT601 USB3 bridge (PCIeScreamer/DMA card data bus)'; Risk = 'HIGH' },
        @{ Id = 'VID_0403&PID_6014'; Label = 'FTDI FT232H (DMA/hardware interface)'; Risk = 'MEDIUM' }
    )
    $hidHardware = @(
        @{ Id = 'VID_1A86'; Label = 'CH340/CH341 serial bridge (common in KMBox and its clones)'; Risk = 'MEDIUM' },
        @{ Id = 'VID_2341'; Label = 'Arduino (can be used as a hardware aim assist)'; Risk = 'MEDIUM' },
        @{ Id = 'VID_16C0'; Label = 'Teensy (microcontroller capable of HID emulation)'; Risk = 'MEDIUM' },
        @{ Id = 'VID_0483&PID_5750'; Label = 'STM32 HID (KMBox B+/Net hardware)'; Risk = 'HIGH' },
        @{ Id = 'VID_1B4F'; Label = 'SparkFun microcontroller (HID emulation)'; Risk = 'MEDIUM' }
    )

    try {
        $devices = @(Get-CimInstance Win32_PnPEntity -ErrorAction SilentlyContinue)
        if ($devices.Count -eq 0) { $devices = @(Get-WmiObject Win32_PnPEntity -ErrorAction SilentlyContinue) }

        foreach ($dev in $devices) {
            $devId = $dev.PNPDeviceID
            if (-not $devId) { continue }
            $upperId = $devId.ToUpperInvariant()

            foreach ($h in ($dmaHardware + $hidHardware)) {
                if ($upperId.Contains($h.Id)) {
                    $isDma = $dmaHardware | Where-Object { $_.Id -eq $h.Id }
                    $cat = if ($isDma) { 'DmaDevice' } else { 'SuspiciousHidDevice' }
                    if ($h.Risk -eq 'HIGH' -and $isDma) {
                        Add-Finding $cat "$($dev.Name) [$devId] - $($h.Label)" $null 'HIGH' -Confirmed
                    } else {
                        Add-Finding $cat "$($dev.Name) [$devId] - $($h.Label)" $null $h.Risk
                    }
                    $found++
                    break
                }
            }
        }
    } catch {}

    # Several HID mice at once, one from an unknown vendor: a KMBox indicator.
    try {
        $mice = @(Get-CimInstance Win32_PointingDevice -ErrorAction SilentlyContinue | Where-Object { $_.Status -eq 'OK' })
        if ($mice.Count -ge 3) {
            $names = ($mice | ForEach-Object { $_.Name }) -join '; '
            Add-Finding 'MultiplePointingDevices' "$($mice.Count) pointing devices are registered on this system: $names (hardware aim assists appear as a second HID mouse)" $null 'LOW'
        }
    } catch {}

    if ($found -eq 0) { Write-Host '  [*] Clean, no DMA/hardware cheat device found.' -ForegroundColor Green }
}


$script:fixedDrives = @()
try {
    $script:fixedDrives = @([System.IO.DriveInfo]::GetDrives() | Where-Object { $_.DriveType -eq 'Fixed' -and $_.IsReady } | ForEach-Object { $_.Name })
} catch {}
if ($script:fixedDrives.Count -eq 0) { $script:fixedDrives = @('C:\') }

$script:suspiciousDirs = New-Object System.Collections.Generic.List[string]

# Shared routine for judging a single file. The Everything sweep and the
# built-in disk sweep both use it; duplicating this logic in two places with
# different thresholds made the two scanners disagree about the same file.
function Test-SuspiciousFile {
    param([System.IO.FileInfo]$File, [switch]$InKnownCheatDir)

    $full = $File.FullName
    if (Test-PathExcluded $full) { return }

    $name = $File.Name
    $kw = Get-CheatKeywordMatch $name
    if (-not $kw) { $kw = Get-CheatKeywordMatch $full }

    # Ambiguous extensions such as .bin/.dat are data files, not programs, so
    # "unsigned" and "no PE info" are meaningless for them. In smoke testing AMD
    # shader cache files (DX9Cache\*.bin) and Windows' own UsrClass.dat /
    # WebCacheLock.dat produced MEDIUM findings this way. They are now only
    # judged after confirming they really are executable (MZ header).
    $ext = $File.Extension.ToLowerInvariant()
    if (@('.bin','.dat') -contains $ext) {
        if (-not (Is-ExecutableHeader $full)) { return }
    }

    # --- A. Known cheat brand: evidence on its own ---
    if ($kw -and $kw.Tier -eq 'Strong') {
        $src = Get-DownloadSourceUrl $full
        $srcStr = if ($src) { " [Downloaded from: $src]" } else { '' }
        Add-Finding 'KnownCheatFile' "$full (match: $($kw.Match))$srcStr" $full 'HIGH' -Confirmed
        return
    }

    # --- B. Trusted name, wrong location ---
    if (Test-TrustedProcessName $name) {
        if (-not (Test-TrustedProcessPath $name $full)) {
            if (-not (Test-TrustedSignedFile $full)) {
                Add-Finding 'MasqueradedFile' "$full ('$name' is a trusted name but does not belong in this location)" $full 'HIGH'
            }
        }
        return
    }

    if (Test-TrustedSignedFile $full) { return }

    # --- C. Unsigned file: looking for supporting signals ---
    # "Unsigned + created in the last 30 days" used to be a HIGH finding on its
    # own, which declared every newly installed portable program a cheat. At
    # least two independent signals are now required, and "recently created" is
    # only a supporting signal.
    $rn = Get-RandomNameScore $name
    $pe = Get-PEVersionInfo $full
    $noPe = [bool]($pe -and -not $pe.HasInfo)
    $suspPath = Test-SuspiciousPath $full
    $isHidden = Test-HiddenSystemFile $full
    $isGeneric = Test-GenericMasqueradeName $name
    $isRecent = $false
    try { $isRecent = $File.CreationTime -gt (Get-Date).AddDays(-21) } catch {}
    $mediumKw = ($kw -and $kw.Tier -eq 'Medium')
    $src = Get-DownloadSourceUrl $full

    # A download source on a known cheat site is evidence on its own.
    if ($src) {
        foreach ($dom in $script:cheatDomains) {
            if ($src.ToLowerInvariant().Contains($dom)) {
                Add-Finding 'DownloadedFromCheatSite' "$full -> downloaded from a known cheat site: $src" $full 'HIGH' -Confirmed
                return
            }
        }
    }

    $signals = @()
    if ($isHidden)         { $signals += 'hidden_file' }
    if ($noPe)             { $signals += 'no_pe_info' }
    if ($rn.Score -ge 55)  { $signals += "random_name($($rn.Score))" }
    if ($isGeneric -and $suspPath) { $signals += 'generic_masquerade_name' }
    if ($mediumKw)         { $signals += "cheat_term($($kw.Match))" }
    if ($InKnownCheatDir)  { $signals += 'in_cheat_folder' }

    if ($signals.Count -eq 0) { return }
    # A risky location and a recent creation date are not enough on their own;
    # they only support the other signals.
    if ($suspPath) { $signals += 'risky_location' }
    if ($isRecent) { $signals += 'recently_created' }

    $strongSignals = 0
    if ($isHidden) { $strongSignals++ }
    if ($noPe) { $strongSignals++ }
    if ($rn.Score -ge 55) { $strongSignals++ }
    if ($mediumKw) { $strongSignals++ }
    if ($InKnownCheatDir) { $strongSignals++ }

    if ($strongSignals -lt 2 -and -not ($strongSignals -eq 1 -and $suspPath -and $isRecent)) { return }

    $risk = if ($strongSignals -ge 3) { 'HIGH' } elseif ($strongSignals -ge 2) { 'MEDIUM' } else { 'LOW' }
    $srcStr = if ($src) { "; source=$src" } else { '' }
    $sizeKb = 0
    try { $sizeKb = [math]::Round($File.Length / 1KB, 1) } catch {}
    Add-Finding 'SuspiciousUnsignedFile' "$full [signals: $($signals -join ', '); sizeKB=$sizeKb$srcStr]" $full $risk
}

# ===========================================================================
Invoke-ScanStep 6 $totalSteps 'Searching the Everything index for cheats...' 150 {

    if (-not $script:esAvailable) {
        Write-Host "  [*] Everything unavailable, this layer is skipped (layer 7 covers it instead)." -ForegroundColor DarkYellow
        return
    }

    # --- A. Targeted queries for known brand names ---
    $brandHits = 0
    $idx = 0
    foreach ($brand in $script:kwStrongAll) {
        $idx++
        if (($idx % 3) -eq 0) {
            Write-ScanProgressText 'Everything (brands)' $idx $script:kwStrongAll.Count $brand
            if (Test-StepBudget) { break }
        }
        $results = Invoke-EsQuery "*$brand*" 25
        foreach ($line in $results) {
            if (-not $line) { continue }
            if (Test-PathExcluded $line) { continue }
            if (-not (Test-FileExists $line)) {
                if (-not [System.IO.Directory]::Exists($line)) { continue }
                Add-Finding 'CheatFolder' "$line (match: $brand)" $null 'HIGH' -Confirmed
                $script:suspiciousDirs.Add($line)
                $brandHits++
                continue
            }
            $src = Get-DownloadSourceUrl $line
            $srcStr = if ($src) { " [Downloaded from: $src]" } else { '' }
            Add-Finding 'KnownCheatFile' "$line (match: $brand)$srcStr" $line 'HIGH' -Confirmed
            $brandHits++
        }
    }
    End-ScanProgressText

    # --- B. Broad unsigned-executable sweep ---
    # The query is passed as ONE quoted argument (the old ';' bug meant .dll and
    # .sys files were never scanned).
    Write-Host "  [*] Filtering executables in the index..." -ForegroundColor Cyan
    $allFiles = Invoke-EsQuery "ext:exe;dll;sys" 80000
    if ($allFiles.Count -eq 0) { return }

    # CHEAP filters first (path/name), then EXPENSIVE checks (signature/hash).
    # Calling Get-AuthenticodeSignature directly for every file could trigger a
    # certificate revocation network request, which made the scan take anywhere
    # between 2 and 40 minutes depending on the machine.
    $candidates = New-Object System.Collections.Generic.List[System.IO.FileInfo]
    foreach ($line in $allFiles) {
        if (-not $line) { continue }
        $lower = $line.ToLowerInvariant()
        if ($lower.Contains('\windows\') -or $lower.Contains('\program files\') -or $lower.Contains('\program files (x86)\')) { continue }
        if (Test-PathExcluded $line) { continue }
        try {
            $fi = New-Object System.IO.FileInfo($line)
            if (-not $fi.Exists) { continue }
            $candidates.Add($fi)
        } catch {}
        if ($candidates.Count -ge 4000) { break }
    }

    Write-Host "  [*] Inspecting $($candidates.Count) candidate files in detail..." -ForegroundColor Gray
    $i = 0
    foreach ($fi in $candidates) {
        $i++
        if (($i % 25) -eq 0) {
            Write-ScanProgressText 'Everything (signatures)' $i $candidates.Count $fi.Name
            if (Test-StepBudget) { break }
        }
        Test-SuspiciousFile $fi
    }
}

# ===========================================================================
Invoke-ScanStep 7 $totalSteps 'Scanning risky folders (Desktop/Downloads/Temp)...' 200 {

    # Collect the risky paths.
    $riskPaths = New-Object System.Collections.Generic.List[string]
    foreach ($ud in @((Get-UserPath 'Desktop'), (Get-UserPath 'MyDocuments'), $env:TEMP, $env:APPDATA, $env:LOCALAPPDATA, $env:ProgramData)) {
        if ($ud -and [System.IO.Directory]::Exists($ud)) { $riskPaths.Add($ud) }
    }
    foreach ($d in $script:fixedDrives) {
        $usersPath = Join-Path $d 'Users'
        if ([System.IO.Directory]::Exists($usersPath)) {
            try {
                foreach ($sd in (New-Object System.IO.DirectoryInfo($usersPath)).EnumerateDirectories()) {
                    foreach ($sub in @('Downloads', 'Desktop', 'Documents')) {
                        $p = Join-Path $sd.FullName $sub
                        if ([System.IO.Directory]::Exists($p)) { $riskPaths.Add($p) }
                    }
                }
            } catch {}
        }
        # Non-standard folders at the drive root (D:\cheats and the like).
        try {
            $safeRoots = @('windows','program files','program files (x86)','users','recovery','$recycle.bin','system volume information','documents and settings','perflogs','intel','amd','nvidia','msocache')
            foreach ($sf in (New-Object System.IO.DirectoryInfo($d)).EnumerateDirectories()) {
                if ($safeRoots -contains $sf.Name.ToLowerInvariant()) { continue }
                if (Test-PathExcluded $sf.FullName) { continue }
                $riskPaths.Add($sf.FullName)
            }
        } catch {}
    }

    $uniquePaths = @($riskPaths | Select-Object -Unique)
    Write-Host "  [*] Scanning $($uniquePaths.Count) risky locations..." -ForegroundColor Gray

    $execExt = @('.exe','.dll','.sys','.bin','.dat','.scr','.cpl')
    $archiveExt = @('.zip','.rar','.7z')
    $script:archiveQueue = New-Object System.Collections.Generic.List[System.IO.FileInfo]

    $pathIdx = 0
    foreach ($rp in $uniquePaths) {
        $pathIdx++
        Write-ScanProgressText 'Risky folders' $pathIdx $uniquePaths.Count $rp
        if (Test-StepBudget) { break }

        $lowerRp = $rp.ToLowerInvariant()
        $depth = if ($lowerRp -like '*\temp*' -or $lowerRp -like '*\appdata*' -or $lowerRp -like '*\programdata*') { 2 } else { 3 }

        # Is the folder name itself a cheat brand?
        $dirKw = Get-CheatKeywordMatch ([System.IO.Path]::GetFileName($rp.TrimEnd('\')))
        $isCheatDir = ($dirKw -and $dirKw.Tier -eq 'Strong')
        if ($isCheatDir) {
            Add-Finding 'CheatFolder' "$rp (match: $($dirKw.Match))" $null 'HIGH' -Confirmed
            $script:suspiciousDirs.Add($rp)
        }

        # WALK THE TREE ONCE. Re-walking it per pattern meant the same directory
        # was scanned hundreds of times (76 folder patterns + 130 file patterns).
        $files = Get-FilesBounded -Root $rp -MaxDepth $depth -MaxFiles 12000
        if ($files.Count -eq 0) { continue }

        foreach ($f in $files) {
            $ext = $f.Extension.ToLowerInvariant()

            if ($archiveExt -contains $ext) {
                if ($script:archiveQueue.Count -lt 400) { $script:archiveQueue.Add($f) }
                continue
            }

            if ($execExt -contains $ext) {
                Test-SuspiciousFile $f -InKnownCheatDir:$isCheatDir
                continue
            }

            # Executable with a disguised extension (MZ header + wrong extension).
            # Only searched on Desktop/Downloads and known cheat folders; Temp
            # and AppData cache files produce far too much noise.
            $scanHidden = ($isCheatDir -or $lowerRp -like '*\desktop*' -or $lowerRp -like '*\downloads*' -or $lowerRp -like '*\documents*')
            if (-not $scanHidden) { continue }
            if ($ext -eq '' -or $ext -eq '.tmp' -or $ext -eq '.log') { continue }

            $safeExt = @('.txt','.md','.json','.xml','.csv','.ini','.cfg','.html','.css','.js','.ts','.py','.cs','.java','.php',
                         '.png','.jpg','.jpeg','.gif','.ico','.bmp','.svg','.webp','.tiff','.psd','.ai',
                         '.mp3','.mp4','.wav','.ogg','.flac','.avi','.mkv','.mov','.webm','.wmv','.aac',
                         '.pdf','.doc','.docx','.xls','.xlsx','.ppt','.pptx','.rtf','.odt',
                         '.ttf','.otf','.woff','.woff2','.eot','.lnk','.url','.torrent','.iso','.img',
                         '.dwg','.dxf','.step','.stl','.blend','.fbx','.obj','.gltf','.glb','.unity','.asset','.meta')
            if ($safeExt -contains $ext) { continue }
            try {
                if ($f.Length -gt 25MB -or $f.Length -lt 64) { continue }
            } catch { continue }

            # Skip GUID/hash named cache files.
            $baseName = [System.IO.Path]::GetFileNameWithoutExtension($f.Name)
            if ($baseName -match '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$') { continue }

            if ((Is-ExecutableHeader $f.FullName) -and -not (Test-TrustedSignedFile $f.FullName)) {
                $src = Get-DownloadSourceUrl $f.FullName
                $srcStr = if ($src) { "; source=$src" } else { '' }
                Add-Finding 'DisguisedExecutable' "$($f.FullName) (the file has a '$ext' extension but its contents are an executable program - MZ header + unsigned$srcStr)" $f.FullName 'HIGH'
            }
        }
    }
    End-ScanProgressText
}

# ===========================================================================
Invoke-ScanStep 8 $totalSteps 'Scanning archives...' 90 {

    if (-not $script:archiveQueue -or $script:archiveQueue.Count -eq 0) {
        Write-Host '  [*] No archives to inspect.' -ForegroundColor Green
        return
    }

    $exe7z = @("C:\Program Files\7-Zip\7z.exe", "C:\Program Files (x86)\7-Zip\7z.exe") |
             Where-Object { Test-FileExists $_ } | Select-Object -First 1
    $exeRar = @("C:\Program Files\WinRAR\Rar.exe", "C:\Program Files\WinRAR\UnRAR.exe") |
              Where-Object { Test-FileExists $_ } | Select-Object -First 1

    $idx = 0
    $found = 0
    foreach ($arc in $script:archiveQueue) {
        $idx++
        Write-ScanProgressText 'Archives' $idx $script:archiveQueue.Count $arc.Name
        if (Test-StepBudget) { break }
        if (Test-PathExcluded $arc.FullName) { continue }

        # If the archive NAME already carries a cheat brand there is no need to open it.
        $arcKw = Get-CheatKeywordMatch $arc.Name
        if ($arcKw -and $arcKw.Tier -eq 'Strong') {
            $src = Get-DownloadSourceUrl $arc.FullName
            $srcStr = if ($src) { " [Downloaded from: $src]" } else { '' }
            Add-Finding 'CheatArchive' "$($arc.FullName) (match: $($arcKw.Match))$srcStr" $arc.FullName 'HIGH' -Confirmed
            $found++
            continue
        }

        try { if ($arc.Length -gt 300MB) { continue } } catch { continue }

        $entries = @()
        $ext = $arc.Extension.ToLowerInvariant()
        try {
            if ($ext -eq '.zip') {
                Add-Type -AssemblyName System.IO.Compression.FileSystem -ErrorAction SilentlyContinue
                $zip = [System.IO.Compression.ZipFile]::OpenRead($arc.FullName)
                try {
                    foreach ($e in $zip.Entries) { $entries += $e.FullName }
                } finally { $zip.Dispose() }
            } elseif ($exe7z) {
                $r = & $exe7z l $arc.FullName -ba -y 2>$null
                foreach ($line in $r) { if ($line -match '\s(\S+)$') { $entries += $Matches[1] } }
            } elseif ($exeRar -and $ext -eq '.rar') {
                $r = & $exeRar lb $arc.FullName 2>$null
                foreach ($line in $r) { if ($line) { $entries += $line.Trim() } }
            }
        } catch {}

        # Only EXECUTABLE entries are judged. Looking at every file name meant
        # Ghidra's "DepthFirstSearch.html" and "TurnOffFuncStartSearch.java"
        # docs became HIGH findings, and an anti-cheat project's
        # "docs/showcase/aimbot.gif" screenshot became a MEDIUM finding.
        $execEntryExt = @('.exe','.dll','.sys','.bat','.cmd','.ps1','.vbs','.jar','.msi','.scr','.bin')
        foreach ($entry in $entries) {
            if (-not $entry) { continue }
            $entryExt = ''
            try { $entryExt = ([System.IO.Path]::GetExtension($entry)).ToLowerInvariant() } catch {}
            if (-not ($execEntryExt -contains $entryExt)) { continue }
            $kw = Get-CheatKeywordMatch $entry
            if ($kw -and $kw.Tier -eq 'Strong') {
                Add-Finding 'CheatArchive' "$($arc.FullName) -> entry: $entry (match: $($kw.Match))" $arc.FullName 'HIGH' -Confirmed
                $found++
                break
            }
            if ($kw -and $kw.Tier -eq 'Medium') {
                Add-Finding 'SuspiciousArchive' "$($arc.FullName) -> entry: $entry (match: $($kw.Match))" $arc.FullName 'MEDIUM'
                $found++
                break
            }
        }
    }
    End-ScanProgressText
    if ($found -eq 0) { Write-Host '  [*] Clean, no cheat content found in archives.' -ForegroundColor Green }
}

# ===========================================================================
Invoke-ScanStep 9 $totalSteps 'Scanning download history and source addresses...' 90 {

    # The NTFS Zone.Identifier stream keeps the SOURCE URL of every downloaded
    # file. That is direct evidence for "where did you download this from", and
    # the traces survive even when the file itself has been deleted.
    $found = 0
    $dlPaths = New-Object System.Collections.Generic.List[string]
    foreach ($d in $script:fixedDrives) {
        $usersPath = Join-Path $d 'Users'
        if (-not [System.IO.Directory]::Exists($usersPath)) { continue }
        try {
            foreach ($sd in (New-Object System.IO.DirectoryInfo($usersPath)).EnumerateDirectories()) {
                foreach ($sub in @('Downloads','Desktop')) {
                    $p = Join-Path $sd.FullName $sub
                    if ([System.IO.Directory]::Exists($p)) { $dlPaths.Add($p) }
                }
            }
        } catch {}
    }

    $idx = 0
    foreach ($dp in @($dlPaths | Select-Object -Unique)) {
        $idx++
        Write-ScanProgressText 'Downloads' $idx $dlPaths.Count $dp
        if (Test-StepBudget) { break }

        $files = Get-FilesBounded -Root $dp -MaxDepth 3 -MaxFiles 6000
        foreach ($f in $files) {
            $src = Get-DownloadSourceUrl $f.FullName
            if (-not $src) { continue }
            $lowerSrc = $src.ToLowerInvariant()
            foreach ($dom in $script:cheatDomains) {
                if ($lowerSrc.Contains($dom)) {
                    Add-Finding 'DownloadedFromCheatSite' "$($f.FullName) -> $src (source: $dom)" $f.FullName 'HIGH' -Confirmed
                    $found++
                    break
                }
            }
        }
    }
    End-ScanProgressText
    if ($found -eq 0) { Write-Host '  [*] Clean, nothing downloaded from a cheat site.' -ForegroundColor Green }
}


# Shared routine for judging execution history records (UserAssist / BAM /
# MUICache / Amcache / Prefetch). Duplicating this logic in five places meant
# each copy had a different exclusion list, and updating one left the others
# inconsistent.
function Test-ExecutionArtifact {
    param(
        [string]$Source,      # 'UserAssist' | 'BAM' | 'MUICache' | 'Amcache' | 'Prefetch'
        [string]$Target,      # full path or exe name
        [string]$Extra = ''
    )
    if (-not $Target) { return $false }
    $t = $Target.ToString()
    if ($t.Length -lt 4) { return $false }
    if (Test-PathExcluded $t) { return $false }

    $fileName = $t
    try { if ($t.Contains('\')) { $fileName = [System.IO.Path]::GetFileName($t) } } catch {}
    if (Test-TrustedProcessName $fileName) { return $false }

    $kw = Get-CheatKeywordMatch $fileName
    if (-not $kw -and $t.Contains('\')) { $kw = Get-CheatKeywordMatch $t }

    $exists = Test-FileExists $t
    if ($exists -and (Test-TrustedSignedFile $t)) { return $false }

    # --- Conclusive: a known cheat brand was executed ---
    if ($kw -and $kw.Tier -eq 'Strong') {
        $state = if ($exists) { 'file still present' } else { 'file deleted' }
        $hashPath = if ($exists) { $t } else { $null }
        Add-Finding "${Source}Record" "Known cheat executed in the past: $t ($state; match: $($kw.Match))$Extra" $hashPath 'HIGH' -Confirmed
        return $true
    }

    if ($kw -and $kw.Tier -eq 'Medium') {
        $state = if ($exists) { 'file present' } else { 'file deleted' }
        $hashPath = if ($exists) { $t } else { $null }
        Add-Finding "${Source}Record" "Suspicious program executed in the past: $t ($state; match: $($kw.Match))$Extra" $hashPath 'MEDIUM'
        return $true
    }

    # --- Heuristic: randomly named, risky location, deleted executable ---
    # A RANDOM NAME IS MANDATORY. Treating "risky_location + file_deleted" as
    # sufficient produced 283 meaningless LOW findings in a single scan, because
    # every installer that runs from Temp and deletes itself carries both
    # signals (LM-Studio, vc_redist, AnyDesk, chrome-headless-shell...).
    $rn = Get-RandomNameScore $fileName
    if ($rn.Score -lt 60) { return $false }

    $suspPath = $t.Contains('\') -and (Test-SuspiciousPath $t)

    $signals = @("random_name($($rn.Score))")
    if ($suspPath)        { $signals += 'risky_location' }
    if (-not $exists -and $t.Contains('\')) { $signals += 'file_deleted' }

    if ($signals.Count -lt 2) { return $false }

    $risk = if ($signals.Count -ge 3) { 'MEDIUM' } else { 'LOW' }
    $hashPath = if ($exists) { $t } else { $null }
    Add-Finding "${Source}Record" "$t [signals: $($signals -join ', ')]$Extra" $hashPath $risk
    return $true
}

# ===========================================================================
Invoke-ScanStep 10 $totalSteps 'Scanning Prefetch records (execution history)...' 60 {

    $prefetchPath = "$env:SystemRoot\Prefetch"
    if (-not (Test-Path -LiteralPath $prefetchPath)) {
        Write-Host '  [*] The Prefetch folder could not be accessed.' -ForegroundColor Gray
        return
    }

    $pfFiles = @()
    try { $pfFiles = @([System.IO.Directory]::EnumerateFiles($prefetchPath, '*.pf')) } catch {}
    if ($pfFiles.Count -eq 0) {
        Write-Host '  [*] No Prefetch records could be read (permissions, or it is disabled).' -ForegroundColor Gray
        return
    }

    $found = 0
    $idx = 0
    foreach ($pf in $pfFiles) {
        $idx++
        if (($idx % 20) -eq 0) {
            Write-ScanProgressText 'Prefetch' $idx $pfFiles.Count 'inspecting'
            if (Test-StepBudget) { break }
        }
        $pfName = [System.IO.Path]::GetFileName($pf)
        $execName = $pfName -replace '-[A-F0-9]{8}\.pf$', ''
        $when = ''
        try { $when = " (last run: $(Get-Date ([System.IO.File]::GetLastWriteTime($pf)) -Format 'yyyy-MM-dd HH:mm'))" } catch {}
        if (Test-ExecutionArtifact -Source 'Prefetch' -Target $execName -Extra $when) { $found++ }
    }
    End-ScanProgressText
    if ($found -eq 0) { Write-Host '  [*] Clean, no suspicious programs in the Prefetch records.' -ForegroundColor Green }
}

# ===========================================================================
Invoke-ScanStep 11 $totalSteps 'Scanning recent files and shortcuts...' 90 {

    $found = 0
    $recentPath = "$env:APPDATA\Microsoft\Windows\Recent"

    if (Test-Path -LiteralPath $recentPath) {
        # --- LNK shortcut target analysis ---
        $lnkFiles = @()
        try { $lnkFiles = @([System.IO.Directory]::EnumerateFiles($recentPath, '*.lnk')) } catch {}

        $sh = $null
        try { $sh = New-Object -ComObject WScript.Shell } catch {}

        $idx = 0
        foreach ($lnkFile in $lnkFiles) {
            $idx++
            if (($idx % 10) -eq 0) {
                Write-ScanProgressText 'Shortcuts' $idx $lnkFiles.Count 'inspecting'
                if (Test-StepBudget) { break }
            }
            $lnkName = [System.IO.Path]::GetFileName($lnkFile)

            # Cheat brand in the shortcut NAME.
            $nameKw = Get-CheatKeywordMatch $lnkName
            if ($nameKw -and $nameKw.Tier -eq 'Strong' -and -not (Test-PathExcluded $lnkName)) {
                Add-Finding 'RecentShortcut' "Cheat entry in recent items: $lnkName (match: $($nameKw.Match))" $null 'HIGH' -Confirmed
                $found++
                continue
            }

            if (-not $sh) { continue }
            try {
                $lnk = $sh.CreateShortcut($lnkFile)
                $targetPath = $lnk.TargetPath
                if (-not $targetPath) { continue }
                if (Test-PathExcluded $targetPath) { continue }

                $kw = Get-CheatKeywordMatch $targetPath
                $exists = Test-FileExists $targetPath
                $drive = if ($targetPath -match "^([A-Za-z]:)") { $Matches[1] } else { $null }

                if ($kw -and $kw.Tier -eq 'Strong') {
                    $state = if ($exists) { 'present' } else { 'DELETED' }
                    Add-Finding 'ShortcutTarget' "$lnkName -> $targetPath ($state; match: $($kw.Match))" $(if ($exists) { $targetPath } else { $null }) 'HIGH' -Confirmed
                    $found++
                    continue
                }

                # Executed from an external/USB drive that is no longer connected.
                if ($drive -and $drive -ne 'C:') {
                    $driveObj = Get-CimInstance Win32_LogicalDisk -Filter "DeviceID = '$drive'" -ErrorAction SilentlyContinue
                    if ($driveObj -and ($driveObj.DriveType -eq 2 -or $driveObj.DriveType -eq 4)) {
                        if ($kw -and $kw.Tier -eq 'Medium') {
                            Add-Finding 'CheatOnExternalDrive' "$lnkName -> $targetPath (external drive $drive; match: $($kw.Match))" $null 'MEDIUM'
                            $found++
                            continue
                        }
                    }
                }

                if (-not $exists -and $targetPath -match '\.exe$') {
                    $rn = Get-RandomNameScore ([System.IO.Path]::GetFileName($targetPath))
                    if ($rn.Score -ge 60 -and (Test-SuspiciousPath $targetPath)) {
                        Add-Finding 'ShortcutTarget' "$lnkName -> $targetPath (file DELETED, random name=$($rn.Score), risky location)" $null 'MEDIUM'
                        $found++
                    }
                }
            } catch {}
        }
        End-ScanProgressText
    }

    # --- RecentDocs registry entries ---
    $recentDocsPath = "HKCU:\Software\Microsoft\Windows\CurrentVersion\Explorer\RecentDocs"
    if (Test-Path -LiteralPath $recentDocsPath) {
        $allRdKeys = @(Get-Item -LiteralPath $recentDocsPath -ErrorAction SilentlyContinue) +
                     @(Get-ChildItem -LiteralPath $recentDocsPath -Recurse -ErrorAction SilentlyContinue)
        foreach ($k in $allRdKeys) {
            if (Test-StepBudget) { break }
            if (-not $k) { continue }
            $props = Get-ItemProperty -LiteralPath $k.PSPath -ErrorAction SilentlyContinue
            if (-not $props) { continue }
            foreach ($prop in @($props.PSObject.Properties | Where-Object { $_.Value -is [byte[]] -and $_.Name -match '^\d+$' })) {
                $bytes = $prop.Value
                if (-not $bytes) { continue }
                try {
                    $unicode = [System.Text.Encoding]::Unicode.GetString($bytes)
                    $ascii = [System.Text.Encoding]::ASCII.GetString($bytes)
                } catch { continue }
                foreach ($text in @($unicode, $ascii)) {
                    $kw = Get-CheatKeywordMatch $text
                    if ($kw -and $kw.Tier -eq 'Strong') {
                        # Pull the readable file name around the matched word.
                        $ctx = $kw.Match
                        $m = [regex]::Match($text, '[\w\-\. ]{0,40}' + [regex]::Escape($kw.Match) + '[\w\-\. ]{0,40}')
                        if ($m.Success) { $ctx = $m.Value.Trim() }
                        if (-not (Test-PathExcluded $ctx)) {
                            Add-Finding 'RecentDocsRecord' "Cheat file/folder opened in the past: $ctx" $null 'HIGH' -Confirmed
                            $found++
                        }
                        break
                    }
                }
            }
        }
    }

    if ($found -eq 0) { Write-Host '  [*] Clean, no suspicious entries in recent items.' -ForegroundColor Green }
}

# ===========================================================================
Invoke-ScanStep 12 $totalSteps 'Scanning UserAssist, BAM and MUICache...' 90 {

    $found = 0

    # --- UserAssist (ROT13 encoded) ---
    $uaPath = "HKCU:\Software\Microsoft\Windows\CurrentVersion\Explorer\UserAssist"
    if (Test-Path -LiteralPath $uaPath) {
        foreach ($sk in @(Get-ChildItem -LiteralPath $uaPath -ErrorAction SilentlyContinue)) {
            if (Test-StepBudget) { break }
            $countPath = Join-Path $sk.PSPath "Count"
            if (-not (Test-Path -LiteralPath $countPath)) { continue }
            $props = Get-ItemProperty -LiteralPath $countPath -ErrorAction SilentlyContinue
            if (-not $props) { continue }
            foreach ($prop in @($props.PSObject.Properties | Where-Object { $_.Name -notmatch '^PS' })) {
                $chars = $prop.Name.ToCharArray()
                for ($i = 0; $i -lt $chars.Length; $i++) {
                    $c = [int]$chars[$i]
                    if ($c -ge 97 -and $c -le 122) { $chars[$i] = [char]((($c - 97 + 13) % 26) + 97) }
                    elseif ($c -ge 65 -and $c -le 90) { $chars[$i] = [char]((($c - 65 + 13) % 26) + 65) }
                }
                $decoded = New-Object String($chars, 0, $chars.Length)
                if ($decoded -match '^\{[0-9A-Fa-f\-]+\}$') { continue }
                if (Test-ExecutionArtifact -Source 'UserAssist' -Target $decoded) { $found++ }
            }
        }
    }

    # --- BAM (Background Activity Moderator) ---
    $bamPath = "HKLM:\SYSTEM\CurrentControlSet\Services\bam\State\UserSettings"
    if ($script:isAdmin -and (Test-Path -LiteralPath $bamPath)) {
        foreach ($sid in @(Get-ChildItem -LiteralPath $bamPath -ErrorAction SilentlyContinue)) {
            if (Test-StepBudget) { break }
            $props = Get-ItemProperty -LiteralPath $sid.PSPath -ErrorAction SilentlyContinue
            if (-not $props) { continue }
            foreach ($prop in @($props.PSObject.Properties | Where-Object { $_.Name -notmatch '^PS' -and $_.Name -match '\\' })) {
                # BAM paths look like "\Device\HarddiskVolume3\..." - map to a drive letter.
                $execPath = $prop.Name -replace '^\\Device\\HarddiskVolume\d+', 'C:'
                if (Test-ExecutionArtifact -Source 'BAM' -Target $execPath) { $found++ }
            }
        }
    }

    # --- MUICache ---
    $muiPath = "HKCU:\Software\Classes\Local Settings\Software\Microsoft\Windows\Shell\MuiCache"
    if (Test-Path -LiteralPath $muiPath) {
        $props = Get-ItemProperty -LiteralPath $muiPath -ErrorAction SilentlyContinue
        if ($props) {
            foreach ($prop in @($props.PSObject.Properties | Where-Object { $_.Name -notmatch '^PS' })) {
                if (Test-StepBudget) { break }
                $execPath = $prop.Name -replace '\.(FriendlyAppName|ApplicationCompany)$', ''
                if ($execPath -eq $prop.Name -and $prop.Name -match '\.(FriendlyAppName|ApplicationCompany)$') { continue }
                if (Test-ExecutionArtifact -Source 'MUICache' -Target $execPath) { $found++ }
            }
        }
    }

    if ($found -eq 0) { Write-Host '  [*] Clean, no suspicious programs in the execution history.' -ForegroundColor Green }
}

# ===========================================================================
Invoke-ScanStep 13 $totalSteps 'Scanning Amcache (traces of deleted programs)...' 90 {

    if (-not $script:isAdmin) {
        Write-Host "  [!] No administrator rights, Amcache scan skipped." -ForegroundColor DarkYellow
        return
    }

    $amcacheHive = "$env:SystemRoot\AppCompat\Programs\Amcache.hve"
    if (-not (Test-FileExists $amcacheHive)) {
        Write-Host '  [*] Amcache.hve not found.' -ForegroundColor Gray
        return
    }

    $tempKey = "HKLM\CheatCheckAmcache_$([guid]::NewGuid().ToString('N').Substring(0,8))"
    $mounted = $false
    $found = 0
    try {
        $null = reg load $tempKey $amcacheHive 2>&1
        if ($LASTEXITCODE -ne 0) {
            Write-Host '  [*] The Amcache hive could not be loaded (the file may be locked).' -ForegroundColor Gray
            return
        }
        $mounted = $true

        $regPath = "Registry::$tempKey\Root\InventoryApplicationFile"
        if (Test-Path -LiteralPath $regPath) {
            $appKeys = @(Get-ChildItem -LiteralPath $regPath -ErrorAction SilentlyContinue)
            $idx = 0
            foreach ($ak in $appKeys) {
                $idx++
                if (($idx % 25) -eq 0) {
                    Write-ScanProgressText 'Amcache' $idx $appKeys.Count 'inspecting'
                    if (Test-StepBudget) { break }
                }
                $props = Get-ItemProperty -LiteralPath $ak.PSPath -ErrorAction SilentlyContinue
                if (-not $props) { continue }
                $filePath = $props.LowerCaseLongPath
                if (-not $filePath) { $filePath = $props.FullPath }
                if (-not $filePath) { continue }
                if (Test-ExecutionArtifact -Source 'Amcache' -Target $filePath) { $found++ }
            }
        }
        End-ScanProgressText
    } catch {}
    finally {
        if ($mounted) {
            [gc]::Collect()
            [gc]::WaitForPendingFinalizers()
            Start-Sleep -Milliseconds 300
            # Leaving the hive mounted breaks later scans; retry a few times.
            for ($i = 0; $i -lt 3; $i++) {
                reg unload $tempKey 2>$null | Out-Null
                if ($LASTEXITCODE -eq 0) { break }
                Start-Sleep -Milliseconds 500
            }
        }
    }

    if ($found -eq 0) { Write-Host '  [*] Clean, no suspicious programs in the Amcache records.' -ForegroundColor Green }
}

# ===========================================================================
Invoke-ScanStep 14 $totalSteps 'Scanning startup and persistence points...' 60 {

    $found = 0

    # --- Startup folders ---
    foreach ($sp in @("$env:APPDATA\Microsoft\Windows\Start Menu\Programs\Startup",
                      "$env:ProgramData\Microsoft\Windows\Start Menu\Programs\Startup")) {
        if (-not (Test-Path -LiteralPath $sp)) { continue }
        try {
            foreach ($f in [System.IO.Directory]::EnumerateFiles($sp)) {
                $fname = [System.IO.Path]::GetFileName($f)
                if (Test-PathExcluded $f) { continue }
                $kw = Get-CheatKeywordMatch $fname
                if ($kw -and $kw.Tier -ne 'Weak') {
                    $risk = if ($kw.Tier -eq 'Strong') { 'HIGH' } else { 'MEDIUM' }
                    if ($kw.Tier -eq 'Strong') {
                        Add-Finding 'StartupCheat' "$f (match: $($kw.Match))" $f 'HIGH' -Confirmed
                    } else {
                        Add-Finding 'SuspiciousStartup' "$f (match: $($kw.Match))" $f $risk
                    }
                    $found++
                }
            }
        } catch {}
    }

    # --- Run / RunOnce registry keys ---
    $regKeys = @(
        'HKCU:\Software\Microsoft\Windows\CurrentVersion\Run',
        'HKCU:\Software\Microsoft\Windows\CurrentVersion\RunOnce',
        'HKLM:\Software\Microsoft\Windows\CurrentVersion\Run',
        'HKLM:\Software\Microsoft\Windows\CurrentVersion\RunOnce',
        'HKLM:\Software\Wow6432Node\Microsoft\Windows\CurrentVersion\Run'
    )
    foreach ($rk in $regKeys) {
        $props = Get-ItemProperty -LiteralPath $rk -ErrorAction SilentlyContinue
        if (-not $props) { continue }
        foreach ($prop in @($props.PSObject.Properties | Where-Object { $_.Name -notmatch '^PS' })) {
            $val = [string]$prop.Value
            if (-not $val) { continue }
            if ((Test-PathExcluded $val) -or (Test-PathExcluded $prop.Name)) { continue }

            $kw = Get-CheatKeywordMatch $val
            if (-not $kw) { $kw = Get-CheatKeywordMatch $prop.Name }
            if ($kw -and $kw.Tier -eq 'Strong') {
                Add-Finding 'StartupCheat' "Registry Run: $($prop.Name) = $val (match: $($kw.Match))" $null 'HIGH' -Confirmed
                $found++
                continue
            }

            # Unsigned program auto-starting from a risky location.
            $cleanVal = ($val -replace '^"([^"]+)".*$', '$1') -replace '\s+[-/].*$', ''
            if ((Test-FileExists $cleanVal) -and (Test-SuspiciousPath $cleanVal) -and -not (Test-TrustedSignedFile $cleanVal)) {
                $rn = Get-RandomNameScore ([System.IO.Path]::GetFileName($cleanVal))
                if ($rn.Score -ge 55 -or ($kw -and $kw.Tier -eq 'Medium')) {
                    Add-Finding 'SuspiciousStartup' "Registry Run: $($prop.Name) = $val (unsigned, risky location, random name=$($rn.Score))" $cleanVal 'MEDIUM'
                    $found++
                }
            }
        }
    }

    # --- AppCertDlls / AppInit_DLLs: the classic process-wide DLL injection points ---
    foreach ($injKey in @(
        @{ Path = 'HKLM:\System\CurrentControlSet\Control\Session Manager\AppCertDlls'; Label = 'AppCertDlls' },
        @{ Path = 'HKLM:\Software\Microsoft\Windows NT\CurrentVersion\Windows'; Label = 'AppInit_DLLs'; Value = 'AppInit_DLLs' },
        @{ Path = 'HKLM:\Software\Wow6432Node\Microsoft\Windows NT\CurrentVersion\Windows'; Label = 'AppInit_DLLs(32)'; Value = 'AppInit_DLLs' }
    )) {
        if (-not (Test-Path -LiteralPath $injKey.Path)) { continue }
        $props = Get-ItemProperty -LiteralPath $injKey.Path -ErrorAction SilentlyContinue
        if (-not $props) { continue }
        if ($injKey.Value) {
            $v = [string]$props.($injKey.Value)
            if ($v -and $v.Trim()) {
                Add-Finding 'GlobalDllInjection' "$($injKey.Label) is set: $v - loads a DLL into every process, used for cheat injection" $null 'HIGH'
                $found++
            }
        } else {
            foreach ($prop in @($props.PSObject.Properties | Where-Object { $_.Name -notmatch '^PS' })) {
                Add-Finding 'GlobalDllInjection' "$($injKey.Label): $($prop.Name) = $($prop.Value)" $null 'HIGH'
                $found++
            }
        }
    }

    if ($found -eq 0) { Write-Host '  [*] Clean, no suspicious startup/persistence entries.' -ForegroundColor Green }
}

# ===========================================================================
Invoke-ScanStep 15 $totalSteps 'Scanning scheduled tasks...' 60 {

    $found = 0
    $allTasks = @(Get-ScheduledTask -ErrorAction SilentlyContinue)
    $idx = 0
    foreach ($t in $allTasks) {
        $idx++
        if (($idx % 20) -eq 0) {
            Write-ScanProgressText 'Tasks' $idx $allTasks.Count $t.TaskName
            if (Test-StepBudget) { break }
        }
        if ($t.TaskPath -and $t.TaskPath.StartsWith('\Microsoft\')) { continue }
        if ((Test-PathExcluded $t.TaskName) -or (Test-PathExcluded $t.TaskPath)) { continue }

        $kw = Get-CheatKeywordMatch $t.TaskName
        if ($kw -and $kw.Tier -eq 'Strong') {
            Add-Finding 'CheatScheduledTask' "$($t.TaskName) [$($t.State)] -> $($t.TaskPath) (match: $($kw.Match))" $null 'HIGH' -Confirmed
            $found++
            continue
        }

        foreach ($act in @($t.Actions)) {
            if (-not $act.Execute) { continue }
            $cleanExec = ($act.Execute -replace '^"([^"]+)".*$', '$1').Trim('"')
            if (Test-PathExcluded $cleanExec) { continue }
            $execName = ''
            try { $execName = [System.IO.Path]::GetFileName($cleanExec) } catch { continue }
            if (Test-TrustedProcessName $execName) { continue }

            $akw = Get-CheatKeywordMatch $cleanExec
            if ($akw -and $akw.Tier -eq 'Strong') {
                Add-Finding 'CheatScheduledTask' "$($t.TaskName) -> $cleanExec (match: $($akw.Match))" $(if (Test-FileExists $cleanExec) { $cleanExec } else { $null }) 'HIGH' -Confirmed
                $found++
                break
            }

            if (-not (Test-SuspiciousPath $cleanExec)) { continue }
            if (Test-FileExists $cleanExec) {
                if (Test-TrustedSignedFile $cleanExec) { continue }
                $rn = Get-RandomNameScore $execName
                if ($rn.Score -ge 55 -or ($akw -and $akw.Tier -eq 'Medium')) {
                    Add-Finding 'SuspiciousScheduledTask' "$($t.TaskName) [$($t.State)] -> runs an unsigned program: $cleanExec (random name=$($rn.Score))" $cleanExec 'MEDIUM'
                    $found++
                    break
                }
            } else {
                Add-Finding 'SuspiciousScheduledTask' "$($t.TaskName) [$($t.State)] -> tries to run a deleted file: $cleanExec" $null 'MEDIUM'
                $found++
                break
            }
        }
    }
    End-ScanProgressText
    if ($found -eq 0) { Write-Host '  [*] Clean, no suspicious scheduled tasks.' -ForegroundColor Green }
}

# ===========================================================================
Invoke-ScanStep 16 $totalSteps 'Scanning WMI subscriptions...' 45 {

    $found = 0
    try {
        foreach ($f in @(Get-CimInstance -Namespace root\Subscription -ClassName __EventFilter -ErrorAction SilentlyContinue)) {
            $kw = Get-CheatKeywordMatch "$($f.Name) $($f.Query)"
            if ($kw -and $kw.Tier -ne 'Weak') {
                Add-Finding 'WmiFilter' "$($f.Name) -> $($f.Query) (match: $($kw.Match))" $null 'HIGH'
                $found++
            }
        }
        foreach ($c in @(Get-CimInstance -Namespace root\Subscription -ClassName __EventConsumer -ErrorAction SilentlyContinue)) {
            $text = "$($c.Name) $($c.CommandLineTemplate) $($c.ScriptText)"
            $kw = Get-CheatKeywordMatch $text
            if ($kw -and $kw.Tier -ne 'Weak') {
                Add-Finding 'WmiConsumer' "$($c.Name) -> $($c.CommandLineTemplate) (match: $($kw.Match))" $null 'HIGH'
                $found++
            }
        }
    } catch {}
    if ($found -eq 0) { Write-Host '  [*] Clean, no suspicious WMI subscriptions.' -ForegroundColor Green }
}


# Forum/community sites can mean discussion rather than buying a cheat -> MEDIUM.
# Licensing/storefront sites are in practice only visited when buying one -> HIGH.
$script:forumDomains = @('unknowncheats.me','cheatglobal.com','cheatautomation.com','hackforums.net','mpgh.net','elitepvpers.com')

function Test-PrivateIpAddress {
    param([string]$Address)
    if (-not $Address) { return $true }
    if ($Address -eq '127.0.0.1' -or $Address -eq '::1' -or $Address -eq '0.0.0.0') { return $true }
    if ($Address.StartsWith('fe80:') -or $Address.StartsWith('fc') -or $Address.StartsWith('fd')) { return $true }
    $octets = $Address.Split('.')
    if ($octets.Count -ne 4) { return $false }
    $o0 = 0; $o1 = 0
    if (-not [int]::TryParse($octets[0], [ref]$o0)) { return $false }
    if (-not [int]::TryParse($octets[1], [ref]$o1)) { return $false }
    if ($o0 -eq 10 -or $o0 -eq 127) { return $true }
    # Matching on "172.1*" and "172.2*" both ignored REAL internet addresses
    # like 172.1.x.x and missed 172.30-31.
    if ($o0 -eq 172 -and $o1 -ge 16 -and $o1 -le 31) { return $true }
    if ($o0 -eq 192 -and $o1 -eq 168) { return $true }
    if ($o0 -eq 169 -and $o1 -eq 254) { return $true }
    return $false
}

# ===========================================================================
Invoke-ScanStep 17 $totalSteps 'Scanning network connections and DNS cache...' 60 {

    $found = 0

    # --- Active TCP connections ---
    $netConns = @(Get-NetTCPConnection -State Established -ErrorAction SilentlyContinue)
    $seenPids = @{}
    foreach ($conn in $netConns) {
        if (Test-StepBudget) { break }
        if ($seenPids.ContainsKey($conn.OwningProcess)) { continue }
        $proc = Get-Process -Id $conn.OwningProcess -ErrorAction SilentlyContinue
        if (-not $proc) { continue }

        $kw = Get-CheatKeywordMatch $proc.ProcessName
        if ($kw -and $kw.Tier -eq 'Strong') {
            $seenPids[$conn.OwningProcess] = $true
            Add-Finding 'CheatNetworkConnection' "$($proc.ProcessName) (PID:$($conn.OwningProcess)) -> $($conn.RemoteAddress):$($conn.RemotePort) (match: $($kw.Match))" $null 'HIGH' -Confirmed
            $found++
            continue
        }

        # Unsigned process + a real internet address = possible license check.
        if ($script:unsignedProcessPids.ContainsKey($conn.OwningProcess)) {
            if (Test-PrivateIpAddress $conn.RemoteAddress) { continue }
            $seenPids[$conn.OwningProcess] = $true
            $path = $script:unsignedProcessPids[$conn.OwningProcess]
            Add-Finding 'UnsignedProcessConnection' "$($proc.ProcessName) (PID:$($conn.OwningProcess)) -> $($conn.RemoteAddress):$($conn.RemotePort) | File: $path (an unsigned program is talking to an external server)" $path 'MEDIUM'
            $found++
        }
    }

    # --- DNS cache ---
    foreach ($entry in @(Get-DnsClientCache -ErrorAction SilentlyContinue)) {
        if (Test-StepBudget) { break }
        $name = $entry.Name
        if (-not $name) { continue }
        $lowerName = $name.ToLowerInvariant()
        $hit = $false
        foreach ($d in $script:cheatDomains) {
            if ($lowerName.Contains($d)) {
                $isForum = $script:forumDomains -contains $d
                if ($isForum) {
                    Add-Finding 'DnsCache' "$name -> $($entry.Data) (cheat forum: $d)" $null 'MEDIUM'
                } else {
                    Add-Finding 'DnsCache' "$name -> $($entry.Data) (cheat licensing/storefront server: $d)" $null 'HIGH' -Confirmed
                }
                $found++
                $hit = $true
                break
            }
        }
        if ($hit) { continue }
        $kw = Get-CheatKeywordMatch $name
        if ($kw -and $kw.Tier -eq 'Strong') {
            Add-Finding 'DnsCache' "$name -> $($entry.Data) (match: $($kw.Match))" $null 'HIGH' -Confirmed
            $found++
        }
    }

    if ($found -eq 0) { Write-Host '  [*] Clean, no suspicious network connections or DNS entries.' -ForegroundColor Green }
}

# ===========================================================================
Invoke-ScanStep 18 $totalSteps 'Scanning deleted folder history and the Recycle Bin...' 75 {

    $found = 0

    # --- ShellBags: a record of folders that were opened (and later deleted) ---
    $shellBagPaths = @(
        "HKCU:\Software\Classes\Local Settings\Software\Microsoft\Windows\Shell\BagMRU",
        "HKCU:\Software\Microsoft\Windows\Shell\BagMRU"
    )
    foreach ($sbRoot in $shellBagPaths) {
        if (-not (Test-Path -LiteralPath $sbRoot)) { continue }
        $keys = @(Get-Item -LiteralPath $sbRoot -ErrorAction SilentlyContinue) +
                @(Get-ChildItem -LiteralPath $sbRoot -Recurse -ErrorAction SilentlyContinue)
        $idx = 0
        foreach ($k in $keys) {
            $idx++
            if (($idx % 25) -eq 0) {
                Write-ScanProgressText 'ShellBags' $idx $keys.Count 'inspecting'
                if (Test-StepBudget) { break }
            }
            if (-not $k) { continue }
            $props = Get-ItemProperty -LiteralPath $k.PSPath -ErrorAction SilentlyContinue
            if (-not $props) { continue }
            foreach ($prop in @($props.PSObject.Properties | Where-Object { $_.Value -is [byte[]] -and $_.Name -match '^\d+$' })) {
                $bytes = $prop.Value
                if (-not $bytes) { continue }
                try {
                    $unicode = [System.Text.Encoding]::Unicode.GetString($bytes)
                    $ascii = [System.Text.Encoding]::ASCII.GetString($bytes)
                } catch { continue }
                foreach ($text in @($unicode, $ascii)) {
                    $kw = Get-CheatKeywordMatch $text
                    if ($kw -and $kw.Tier -eq 'Strong') {
                        $ctx = $kw.Match
                        $m = [regex]::Match($text, '[\w\-\. ]{0,40}' + [regex]::Escape($kw.Match) + '[\w\-\. ]{0,40}')
                        if ($m.Success) { $ctx = $m.Value.Trim() }
                        if (-not (Test-PathExcluded $ctx)) {
                            Add-Finding 'ShellBagRecord' "Cheat folder opened in the past (the record survives even after deletion): $ctx" $null 'HIGH' -Confirmed
                            $found++
                        }
                        break
                    }
                }
            }
        }
        End-ScanProgressText
    }

    # --- Recycle Bin: deleted cheat files ---
    foreach ($d in $script:fixedDrives) {
        $rb = Join-Path $d '$Recycle.Bin'
        if (-not [System.IO.Directory]::Exists($rb)) { continue }
        if (Test-StepBudget) { break }
        # The exclusion engine normally skips $recycle.bin, so the files are read directly.
        try {
            foreach ($userBin in (New-Object System.IO.DirectoryInfo($rb)).EnumerateDirectories()) {
                foreach ($f in $userBin.EnumerateFiles('$I*')) {
                    # The $I file holds the ORIGINAL path of the deleted file.
                    try {
                        $bytes = [System.IO.File]::ReadAllBytes($f.FullName)
                        if ($bytes.Length -lt 30) { continue }
                        $orig = [System.Text.Encoding]::Unicode.GetString($bytes, 24, $bytes.Length - 24).Trim([char]0)
                        if (-not $orig) { continue }
                        $kw = Get-CheatKeywordMatch $orig
                        if ($kw -and $kw.Tier -eq 'Strong' -and -not (Test-PathExcluded $orig)) {
                            Add-Finding 'RecycleBin' "Cheat file deleted into the Recycle Bin: $orig (match: $($kw.Match))" $null 'HIGH' -Confirmed
                            $found++
                        }
                    } catch {}
                }
            }
        } catch {}
    }

    if ($found -eq 0) { Write-Host '  [*] Clean, no traces of deleted cheats found.' -ForegroundColor Green }
}

# ===========================================================================
Invoke-ScanStep 19 $totalSteps 'Analysing event logs...' 60 {

    $found = 0
    $since = (Get-Date).AddDays(-14)

    # --- Has an event log been cleared? (anti-forensics) ---
    foreach ($spec in @(@{ Log = 'Security'; Id = 1102 }, @{ Log = 'System'; Id = 104 })) {
        try {
            foreach ($evt in @(Get-WinEvent -FilterHashtable @{LogName=$spec.Log; ID=$spec.Id; StartTime=$since} -MaxEvents 20 -ErrorAction SilentlyContinue)) {
                Add-Finding 'EventLogCleared' "The $($spec.Log) log was cleared (at: $($evt.TimeCreated)) - an evidence removal indicator" $null 'MEDIUM'
                $found++
            }
        } catch {}
    }

    # --- New service/driver installs (7045) ---
    try {
        foreach ($evt in @(Get-WinEvent -FilterHashtable @{LogName='System'; ID=7045; StartTime=$since} -MaxEvents 200 -ErrorAction SilentlyContinue)) {
            $msg = [string]$evt.Message
            if (-not $msg) { continue }
            if (Test-PathExcluded $msg) { continue }
            $kw = Get-CheatKeywordMatch $msg
            if ($kw -and $kw.Tier -eq 'Strong') {
                Add-Finding 'SuspiciousServiceInstall' "$($evt.TimeCreated): $($msg -replace '\s+', ' ')" $null 'HIGH' -Confirmed
                $found++
                continue
            }
            # Tools like kdmapper load a driver and delete it immediately; this
            # event is the trace they leave behind.
            foreach ($vd in ($script:kdmapperDrivers + $script:abusableDrivers)) {
                if ($msg.ToLowerInvariant().Contains($vd)) {
                    $risk = if ($script:kdmapperDrivers -contains $vd) { 'HIGH' } else { 'MEDIUM' }
                    Add-Finding 'VulnerableDriverInstall' "$($evt.TimeCreated): driver '$vd' was installed on this system - $($msg -replace '\s+', ' ')" $null $risk
                    $found++
                    break
                }
            }
        }
    } catch {}

    if ($found -eq 0) { Write-Host '  [*] Clean, nothing wrong in the event logs.' -ForegroundColor Green }
}

# ===========================================================================
Invoke-ScanStep 20 $totalSteps 'Scanning browser history...' 90 {

    $found = 0
    $localApp = $env:LOCALAPPDATA
    $roamingApp = $env:APPDATA

    $historyPaths = @(
        @{ Path = Join-Path $localApp "Google\Chrome\User Data\Default\History"; Name = "Chrome" },
        @{ Path = Join-Path $localApp "Microsoft\Edge\User Data\Default\History"; Name = "Edge" },
        @{ Path = Join-Path $localApp "BraveSoftware\Brave-Browser\User Data\Default\History"; Name = "Brave" },
        @{ Path = Join-Path $localApp "Vivaldi\User Data\Default\History"; Name = "Vivaldi" },
        @{ Path = Join-Path $localApp "Yandex\YandexBrowser\User Data\Default\History"; Name = "Yandex" },
        @{ Path = Join-Path $roamingApp "Opera Software\Opera Stable\History"; Name = "Opera" },
        @{ Path = Join-Path $localApp "Opera Software\Opera GX Stable\History"; Name = "Opera GX" }
    )
    $ffProfilesDir = Join-Path $roamingApp "Mozilla\Firefox\Profiles"
    if (Test-Path -LiteralPath $ffProfilesDir) {
        try {
            foreach ($p in (New-Object System.IO.DirectoryInfo($ffProfilesDir)).EnumerateDirectories()) {
                $dbPath = Join-Path $p.FullName "places.sqlite"
                if (Test-FileExists $dbPath) { $historyPaths += @{ Path = $dbPath; Name = "Firefox ($($p.Name))" } }
            }
        } catch {}
    }

    foreach ($db in $historyPaths) {
        if (Test-StepBudget) { break }
        if (-not (Test-FileExists $db.Path)) { continue }

        $tempCopy = Join-Path $env:TEMP "cc_hist_$([Guid]::NewGuid().ToString('N')).db"
        try {
            Copy-Item -LiteralPath $db.Path -Destination $tempCopy -Force -ErrorAction SilentlyContinue
            if (-not (Test-FileExists $tempCopy)) {
                # The file can be locked while the browser is open - tell the
                # admin instead of skipping silently.
                $script:scanNotes.Add("The $($db.Name) history file was locked and could not be read (the browser may be open).")
                continue
            }

            # Reading the whole file with ReadAllText pulled 100MB+ into RAM.
            # It is now read in chunks, with an overlap so matches spanning a
            # chunk boundary are not missed.
            $fi = New-Object System.IO.FileInfo($tempCopy)
            if ($fi.Length -gt 120MB) { continue }

            $matchedShop = New-Object System.Collections.Generic.HashSet[string]
            $matchedForum = New-Object System.Collections.Generic.HashSet[string]
            $fs = [System.IO.File]::OpenRead($tempCopy)
            try {
                $bufSize = 1MB
                $buffer = New-Object byte[] $bufSize
                $carry = ''
                while ($true) {
                    $read = $fs.Read($buffer, 0, $bufSize)
                    if ($read -le 0) { break }
                    $chunk = $carry + [System.Text.Encoding]::ASCII.GetString($buffer, 0, $read)
                    $lowerChunk = $chunk.ToLowerInvariant()
                    foreach ($dom in $script:cheatDomains) {
                        if ($lowerChunk.Contains($dom)) {
                            if ($script:forumDomains -contains $dom) { [void]$matchedForum.Add($dom) }
                            else { [void]$matchedShop.Add($dom) }
                        }
                    }
                    $carry = if ($chunk.Length -gt 64) { $chunk.Substring($chunk.Length - 64) } else { $chunk }
                    if (Test-StepBudget) { break }
                }
            } finally { $fs.Dispose() }

            foreach ($dom in $matchedShop) {
                Add-Finding 'BrowserCheatSite' "Cheat storefront/licensing site in the $($db.Name) history: $dom" $null 'HIGH' -Confirmed
                $found++
            }
            foreach ($dom in $matchedForum) {
                Add-Finding 'BrowserCheatForum' "Cheat forum in the $($db.Name) history: $dom (not evidence on its own)" $null 'MEDIUM'
                $found++
            }
        } catch {} finally {
            if (Test-FileExists $tempCopy) { Remove-Item -LiteralPath $tempCopy -Force -ErrorAction SilentlyContinue }
        }
    }

    if ($found -eq 0) { Write-Host '  [*] Clean, no cheat sites in the browser history.' -ForegroundColor Green }
}

# ===========================================================================
#  Result / report
# ===========================================================================

$duration = ((Get-Date) - $scanStart).TotalSeconds
$verdict = Get-ScanVerdict
$highCount = @($script:findings | Where-Object { $_ -like '`[HIGH`]*' }).Count
$mediumCount = @($script:findings | Where-Object { $_ -like '`[MEDIUM`]*' }).Count
$lowCount = @($script:findings | Where-Object { $_ -like '`[LOW`]*' }).Count

Write-Host ''
Write-Host '  ===================================================' -ForegroundColor Cyan
Write-Host '                    SCAN SUMMARY                     ' -ForegroundColor White
Write-Host '  ===================================================' -ForegroundColor Cyan
Write-Host "  Duration       : $([math]::Round($duration, 1))s" -ForegroundColor Cyan
Write-Host "  Total findings : $($script:findings.Count)" -ForegroundColor Cyan
Write-Host "  High risk      : $highCount" -ForegroundColor $(if ($highCount -gt 0) { 'Red' } else { 'Green' })
Write-Host "  Medium risk    : $mediumCount" -ForegroundColor $(if ($mediumCount -gt 0) { 'Yellow' } else { 'Green' })
Write-Host "  Low risk       : $lowCount" -ForegroundColor Gray
Write-Host "  Risk score     : $($script:riskScore)" -ForegroundColor Cyan
Write-Host "  Disk coverage  : $(if ($script:scanCoverage -eq 'everything') { 'Everything index (wide)' } else { 'Built-in walker (limited)' })" -ForegroundColor Cyan
Write-Host "  Privileges     : $(if ($script:isAdmin) { 'Administrator' } else { 'LIMITED - some layers are missing' })" -ForegroundColor $(if ($script:isAdmin) { 'Cyan' } else { 'DarkYellow' })
if ($script:partialScan) {
    Write-Host "  WARNING        : The scan finished only partially (see the notes below)" -ForegroundColor DarkYellow
}

if ($script:scanNotes.Count -gt 0) {
    Write-Host ''
    Write-Host '  [i] Scan notes:' -ForegroundColor DarkYellow
    foreach ($n in $script:scanNotes) { Write-Host "      - $n" -ForegroundColor DarkYellow }
}

Write-Host ''
Write-Host '  ===================================================' -ForegroundColor Cyan
Write-Host '                    SCAN RESULT                      ' -ForegroundColor White
Write-Host '  ===================================================' -ForegroundColor Cyan
switch ($verdict) {
    'cheat' {
        Write-Host '     !!! CHEAT / PROHIBITED SOFTWARE DETECTED !!!    ' -ForegroundColor Red -BackgroundColor Black
        Write-Host '            Conclusive findings are present.        ' -ForegroundColor Red
    }
    'suspicious' {
        Write-Host '        SUSPICIOUS: no conclusive evidence          ' -ForegroundColor Yellow -BackgroundColor Black
        Write-Host '      The findings need to be reviewed manually.    ' -ForegroundColor Yellow
    }
    default {
        Write-Host '          CLEAN: no signs of cheating found.        ' -ForegroundColor Green
    }
}
Write-Host '  ===================================================' -ForegroundColor Cyan
Write-Host ''
Write-Host '  [*] Note: some DMA/HWID based cheats cannot be detected' -ForegroundColor Gray
Write-Host '      in software. This scan is a preliminary check.' -ForegroundColor Gray
Write-Host ''

# --- Finish the log file ---
if ($script:logWriter) {
    try {
        $script:logWriter.WriteLine('')
        $script:logWriter.WriteLine('===================================================')
        $script:logWriter.WriteLine("Date          : $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')")
        $script:logWriter.WriteLine("Computer      : $env:COMPUTERNAME")
        $script:logWriter.WriteLine("User          : $env:USERNAME")
        $script:logWriter.WriteLine("Result        : $verdict")
        $script:logWriter.WriteLine("Risk score    : $($script:riskScore)")
        $script:logWriter.WriteLine("Findings      : $($script:findings.Count) (HIGH=$highCount MEDIUM=$mediumCount LOW=$lowCount)")
        $script:logWriter.WriteLine("Duration      : $([math]::Round($duration, 1))s")
        $script:logWriter.WriteLine("Disk coverage : $($script:scanCoverage)")
        $script:logWriter.WriteLine("Administrator : $($script:isAdmin)")
        $script:logWriter.WriteLine("Partial scan  : $($script:partialScan)")
        foreach ($n in $script:scanNotes) { $script:logWriter.WriteLine("Note          : $n") }
        $script:logWriter.WriteLine('===================================================')
        $script:logWriter.Flush()
        $script:logWriter.Dispose()
        $script:logWriter = $null
    } catch {}
}

Write-Host "  [*] Sending the results to the panel..." -ForegroundColor Cyan
$sent = Send-PanelResults -LogPath $logFile -Duration $duration

if ($sent) {
    Write-Host "  [+] Report sent successfully." -ForegroundColor Green
    if (Test-FileExists $logFile) { Remove-Item -LiteralPath $logFile -Force -ErrorAction SilentlyContinue }
} else {
    Write-Host "  [-] The report could not be sent." -ForegroundColor Red
    # On a failed upload the log is NOT deleted, so the admin can ask for it manually.
    Write-Host "  [i] The report was kept at: $logFile" -ForegroundColor Yellow
}

Write-Host ''
Write-Host '  [*] Press any key to close this window...' -ForegroundColor Cyan

# Calling ReadKey unconditionally fails silently when input is redirected or the
# host has no RawUI support (irm|iex, ISE, service contexts) - the window then
# closed instantly, the other half of the "sometimes it closes really fast" reports.
$paused = $false
try {
    if (-not [Console]::IsInputRedirected -and $Host.UI.RawUI) {
        $null = $Host.UI.RawUI.ReadKey("NoEcho,IncludeKeyDown")
        $paused = $true
    }
} catch { $paused = $false }

if (-not $paused) {
    Write-Host '  (No key could be read, this window will close in 60 seconds.)' -ForegroundColor DarkGray
    Start-Sleep -Seconds 60
}
