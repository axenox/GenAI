param(
    [Parameter(Mandatory = $true)]
    [string]$EndpointSelector
)

$ErrorActionPreference = 'Stop'
$server = Resolve-Path (Join-Path $PSScriptRoot '..\..\..\..\bin\mcp')
$inputFile = Resolve-Path (Join-Path $PSScriptRoot 'endpoint-smoke.jsonl')
$php = (Get-Command php -CommandType Application -ErrorAction Stop).Source
$runId = [Guid]::NewGuid().ToString('N')
$stdoutFile = Join-Path $PSScriptRoot "smoke-$runId.stdout"
$stderrFile = Join-Path $PSScriptRoot "smoke-$runId.stderr"

try {
    $ErrorActionPreference = 'Continue'
    try {
        $stdout = Get-Content $inputFile.Path | & $php $server.Path $EndpointSelector 2> $stderrFile
        $process = [PSCustomObject]@{ ExitCode = $LASTEXITCODE }
        $stdout | Set-Content $stdoutFile -Encoding UTF8
    } finally {
        $ErrorActionPreference = 'Stop'
    }

    if ($process.ExitCode -ne 0) {
        throw "MCP endpoint exited with code $($process.ExitCode): $(Get-Content $stderrFile -Raw)"
    }

    $responses = @()
    foreach ($line in Get-Content $stdoutFile) {
        if ([string]::IsNullOrWhiteSpace($line)) {
            continue
        }
        try {
            $responses += $line | ConvertFrom-Json
        } catch {
            throw "STDOUT contains a non-JSON protocol line: $line"
        }
    }

    foreach ($id in 1..8) {
        if (-not ($responses | Where-Object { $_.id -eq $id })) {
            throw "Missing JSON-RPC response for request id $id"
        }
    }

    $toolsResponse = $responses | Where-Object { $_.id -eq 2 } | Select-Object -First 1
    $searchTool = $toolsResponse.result.tools | Where-Object { $_.name -eq 'SearchModelComponents' }
    if (-not $searchTool) {
        throw "tools/list did not advertise SearchModelComponents"
    }
    if ($searchTool.inputSchema.required -notcontains 'search_query') {
        throw "SearchModelComponents schema did not require search_query"
    }

    $validCall = $responses | Where-Object { $_.id -eq 3 } | Select-Object -First 1
    if ($validCall.result.isError -eq $true -or $null -ne $validCall.error) {
        throw "The valid SearchModelComponents call failed"
    }

    foreach ($id in 4, 5, 6) {
        $invalidCall = $responses | Where-Object { $_.id -eq $id } | Select-Object -First 1
        if ($null -eq $invalidCall.error) {
            throw "Schema-invalid call $id did not return a protocol validation error"
        }
    }

    $emptyRequiredCall = $responses | Where-Object { $_.id -eq 7 } | Select-Object -First 1
    if ($emptyRequiredCall.result.isError -ne $true) {
        throw "The empty required value was not returned as a recoverable tool error"
    }

    $pingResponse = $responses | Where-Object { $_.id -eq 8 } | Select-Object -First 1
    if ($null -ne $pingResponse.error) {
        throw "The server did not recover from endpoint validation failures"
    }

    $unknownTool = $responses | Where-Object { $_.id -eq 9 } | Select-Object -First 1
    if ($null -eq $unknownTool -or $unknownTool.error.code -ne -32602) {
        throw "Unknown tool did not retain the official SDK invalid-parameters rejection"
    }

    Write-Host "MCP endpoint smoke test passed: schema, invocation, validation, recovery, clean STDOUT."
    $stderr = Get-Content $stderrFile -Raw
    if (-not [string]::IsNullOrWhiteSpace($stderr)) {
        Write-Host "Server STDERR:"
        Write-Host $stderr
    }
} finally {
    Remove-Item $stdoutFile, $stderrFile -Force -ErrorAction SilentlyContinue
}