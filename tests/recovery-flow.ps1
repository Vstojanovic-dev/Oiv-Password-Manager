param(
    [string]$BaseUrl = "http://127.0.0.1:8000/backend/api",
    [switch]$SkipDockerUp
)

$ErrorActionPreference = "Stop"

function Write-Step {
    param([string]$Message)
    Write-Host "[TEST] $Message"
}

function Invoke-JsonApi {
    param(
        [Parameter(Mandatory = $true)][string]$Method,
        [Parameter(Mandatory = $true)][string]$Path,
        [object]$Body = $null,
        [Microsoft.PowerShell.Commands.WebRequestSession]$Session = $null,
        [string]$CsrfToken = $null,
        [int[]]$ExpectedStatus = @(200)
    )

    $headers = @{}
    if ($CsrfToken) {
        $headers["X-CSRF-Token"] = $CsrfToken
    }

    $request = @{
        Uri             = "$BaseUrl$Path"
        Method          = $Method
        Headers         = $headers
        ContentType     = "application/json"
        UseBasicParsing = $true
    }

    if ($Session) {
        $request.WebSession = $Session
    }
    if ($null -ne $Body) {
        $request.Body = ($Body | ConvertTo-Json -Depth 8)
    }

    try {
        $response = Invoke-WebRequest @request
        $status = [int]$response.StatusCode
        $json = $response.Content | ConvertFrom-Json
    } catch {
        if ($_.Exception.Response -eq $null) {
            throw
        }

        $status = [int]$_.Exception.Response.StatusCode.value__
        $stream = $_.Exception.Response.GetResponseStream()
        $reader = New-Object System.IO.StreamReader($stream)
        $content = $reader.ReadToEnd()
        $json = if ($content) { $content | ConvertFrom-Json } else { $null }
    }

    if ($ExpectedStatus -notcontains $status) {
        $payload = if ($json) { $json | ConvertTo-Json -Depth 8 } else { "<empty>" }
        throw "Expected HTTP $($ExpectedStatus -join '/') for $Method $Path, got $status. Payload: $payload"
    }

    return [PSCustomObject]@{
        Status = $status
        Json   = $json
    }
}

function Assert-True {
    param([bool]$Condition, [string]$Message)
    if (-not $Condition) {
        throw $Message
    }
}

function Wait-ForHealth {
    for ($i = 1; $i -le 40; $i++) {
        try {
            $health = Invoke-JsonApi -Method GET -Path "/health" -ExpectedStatus @(200)
            if ($health.Json.success -and $health.Json.data.database -eq "ok") {
                return
            }
        } catch {
            Start-Sleep -Seconds 2
        }
        Start-Sleep -Seconds 2
    }

    throw "Application did not become healthy at $BaseUrl/health."
}

if (-not $SkipDockerUp) {
    Write-Step "Starting Docker stack"
    docker compose up -d --build | Out-Host
}

Write-Step "Waiting for API and database health"
Wait-ForHealth

$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$suffix = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
$username = "recovery_test_$suffix"
$oldPassword = "OldMasterPass123!"
$newPassword = "NewMasterPass123!"
$siteName = "Example Login"
$siteUser = "demo@example.com"
$sitePassword = "PreservedSitePassword123!"

Write-Step "Registering new user: $username"
$register = Invoke-JsonApi -Method POST -Path "/auth/register" -Session $session -Body @{
    username = $username
    password = $oldPassword
} -ExpectedStatus @(201)
Assert-True $register.Json.success "Register failed."
$csrf = $register.Json.data.csrf_token
Assert-True ([string]::IsNullOrWhiteSpace($csrf) -eq $false) "Register did not return CSRF token."

Write-Step "Logging out after registration so login is tested explicitly"
Invoke-JsonApi -Method POST -Path "/auth/logout" -Session $session -CsrfToken $csrf | Out-Null

Write-Step "Logging in with original master password"
$login = Invoke-JsonApi -Method POST -Path "/auth/login" -Session $session -Body @{
    username = $username
    password = $oldPassword
}
Assert-True $login.Json.success "Login with original password failed."
$csrf = $login.Json.data.csrf_token

Write-Step "Creating one vault entry"
$created = Invoke-JsonApi -Method POST -Path "/accounts" -Session $session -CsrfToken $csrf -Body @{
    site_name = $siteName
    site_url  = "https://example.com"
    username  = $siteUser
    password  = $sitePassword
} -ExpectedStatus @(201)
Assert-True $created.Json.success "Account creation failed."
Assert-True ($created.Json.data.password -eq $sitePassword) "Created account did not return expected plaintext password."

Write-Step "Generating recovery codes"
$codes = Invoke-JsonApi -Method POST -Path "/auth/recovery-codes" -Session $session -CsrfToken $csrf -Body @{
    current_password = $oldPassword
} -ExpectedStatus @(201)
Assert-True ($codes.Json.data.codes.Count -eq 8) "Expected 8 recovery codes."
$recoveryCode = $codes.Json.data.codes[0]

Write-Step "Logging out before recovery reset"
Invoke-JsonApi -Method POST -Path "/auth/logout" -Session $session -CsrfToken $csrf | Out-Null

Write-Step "Verifying recovery code"
$verify = Invoke-JsonApi -Method POST -Path "/auth/forgot/verify" -Session $session -Body @{
    username      = $username
    recovery_code = $recoveryCode
}
Assert-True $verify.Json.success "Recovery code verification failed."
$resetToken = $verify.Json.data.reset_token
Assert-True ([string]::IsNullOrWhiteSpace($resetToken) -eq $false) "Verify did not return reset token."

Write-Step "Resetting master password with recovery token"
$reset = Invoke-JsonApi -Method POST -Path "/auth/forgot/reset" -Session $session -Body @{
    reset_token   = $resetToken
    new_password  = $newPassword
}
Assert-True $reset.Json.success "Recovery password reset failed."
$csrf = $reset.Json.data.csrf_token

Write-Step "Logging out after reset"
Invoke-JsonApi -Method POST -Path "/auth/logout" -Session $session -CsrfToken $csrf | Out-Null

Write-Step "Checking that old password no longer logs in"
$oldLogin = Invoke-JsonApi -Method POST -Path "/auth/login" -Session $session -Body @{
    username = $username
    password = $oldPassword
} -ExpectedStatus @(401)
Assert-True (-not $oldLogin.Json.success) "Old password was accepted after recovery reset."

Write-Step "Logging in with new password"
$newLogin = Invoke-JsonApi -Method POST -Path "/auth/login" -Session $session -Body @{
    username = $username
    password = $newPassword
}
Assert-True $newLogin.Json.success "New password login failed."

Write-Step "Checking vault entry password is preserved"
$list = Invoke-JsonApi -Method GET -Path "/accounts" -Session $session
Assert-True ($list.Json.data.Count -ge 1) "No accounts returned after recovery reset."
$entry = $list.Json.data | Where-Object { $_.site_name -eq $siteName } | Select-Object -First 1
Assert-True ($null -ne $entry) "Created vault entry was not found after recovery reset."
Assert-True ($entry.password -eq $sitePassword) "Vault password changed after recovery reset. Expected '$sitePassword', got '$($entry.password)'."

Write-Step "PASS"
[PSCustomObject]@{
    username = $username
    site_name = $entry.site_name
    site_username = $entry.username
    vault_password_preserved = $true
    old_password_rejected = $true
    new_password_accepted = $true
} | ConvertTo-Json
