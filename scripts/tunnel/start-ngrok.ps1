Param(
    [string]$Port = "8080",
    [string]$Domain = ""
)

$ErrorActionPreference = 'Stop'

if (-not (Get-Command ngrok -ErrorAction SilentlyContinue)) {
    throw "ngrok no está instalado o no está en PATH."
}

if ([string]::IsNullOrWhiteSpace($env:NGROK_AUTHTOKEN)) {
    throw "Define NGROK_AUTHTOKEN en tu terminal antes de ejecutar este script."
}

if ([string]::IsNullOrWhiteSpace($env:NGROK_DEMO_USER) -or [string]::IsNullOrWhiteSpace($env:NGROK_DEMO_PASS)) {
    throw "Define NGROK_DEMO_USER y NGROK_DEMO_PASS para proteger el túnel con basic-auth."
}

ngrok config add-authtoken $env:NGROK_AUTHTOKEN | Out-Null

$basicAuth = "$($env:NGROK_DEMO_USER):$($env:NGROK_DEMO_PASS)"

if (-not [string]::IsNullOrWhiteSpace($Domain)) {
    ngrok http $Port --domain=$Domain --basic-auth=$basicAuth --scheme=https
} else {
    ngrok http $Port --basic-auth=$basicAuth --scheme=https
}
