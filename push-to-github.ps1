# GitHub Push Script for OnlyNote Project
# Bu script tüm değişiklikleri GitHub'a push eder

Write-Host "=== GitHub Push Script ===" -ForegroundColor Green
Write-Host ""

# Proje dizinine git
$projectPath = "C:\Users\tarik\OneDrive\Masaüstü\projects\bootstrap\onlynote"
Write-Host "Proje dizinine gidiliyor: $projectPath" -ForegroundColor Yellow

if (-not (Test-Path $projectPath)) {
    Write-Host "HATA: Proje dizini bulunamadi!" -ForegroundColor Red
    Write-Host "Lutfen dizin yolunu kontrol edin." -ForegroundColor Red
    exit 1
}

Set-Location $projectPath
Write-Host "Dizin: $(Get-Location)" -ForegroundColor Green
Write-Host ""

# Git repository kontrolü
if (-not (Test-Path .git)) {
    Write-Host "Git repository bulunamadi. Olusturuluyor..." -ForegroundColor Yellow
    git init
    Write-Host "Git repository olusturuldu." -ForegroundColor Green
} else {
    Write-Host "Git repository bulundu." -ForegroundColor Green
}
Write-Host ""

# Dosyaları kontrol et
$files = @(
    "deploy-aws-ecr.sh",
    "Dockerfile.app",
    "Dockerfile.mysql",
    "Dockerfile.redis",
    ".gitignore",
    "AWS-ECR-DEPLOYMENT.md",
    "README.md",
    "docker"
)

Write-Host "Dosyalar kontrol ediliyor..." -ForegroundColor Yellow
$missingFiles = @()
foreach ($file in $files) {
    if (Test-Path $file) {
        Write-Host "  [OK] $file" -ForegroundColor Green
    } else {
        Write-Host "  [EKSIK] $file" -ForegroundColor Red
        $missingFiles += $file
    }
}
Write-Host ""

if ($missingFiles.Count -gt 0) {
    Write-Host "UYARI: Bazı dosyalar bulunamadi:" -ForegroundColor Yellow
    $missingFiles | ForEach-Object { Write-Host "  - $_" -ForegroundColor Yellow }
    Write-Host ""
}

# Dosyaları git'e ekle
Write-Host "Dosyalar git'e ekleniyor..." -ForegroundColor Yellow
try {
    git add deploy-aws-ecr.sh Dockerfile.app Dockerfile.mysql Dockerfile.redis .gitignore AWS-ECR-DEPLOYMENT.md README.md docker/ 2>&1 | Out-Null
    Write-Host "Dosyalar eklendi." -ForegroundColor Green
} catch {
    Write-Host "HATA: Dosyalar eklenirken hata olustu: $_" -ForegroundColor Red
    exit 1
}
Write-Host ""

# Git durumunu göster
Write-Host "Git durumu:" -ForegroundColor Yellow
git status --short
Write-Host ""

# Commit yap
Write-Host "Commit yapiliyor..." -ForegroundColor Yellow
$commitMessage = "feat: Microservice yapisi icin AWS ECR deployment scripti ve Dockerfilelar eklendi - AWS IAM hata yonetimi eklendi"
try {
    git commit -m $commitMessage 2>&1 | Out-Null
    Write-Host "Commit yapildi." -ForegroundColor Green
} catch {
    Write-Host "UYARI: Commit yapilamadi (muhtemelen degisiklik yok): $_" -ForegroundColor Yellow
}
Write-Host ""

# Remote kontrolü
Write-Host "GitHub remote kontrol ediliyor..." -ForegroundColor Yellow
$remoteUrl = git remote get-url origin 2>&1
if ($LASTEXITCODE -ne 0) {
    Write-Host "Remote bulunamadi. Ekleniyor..." -ForegroundColor Yellow
    git remote add origin https://github.com/iamdevopser/onlynote.git
    Write-Host "Remote eklendi." -ForegroundColor Green
} else {
    Write-Host "Remote bulundu: $remoteUrl" -ForegroundColor Green
    if ($remoteUrl -notlike "*iamdevopser/onlynote*") {
        Write-Host "Remote URL yanlis. Guncelleniyor..." -ForegroundColor Yellow
        git remote set-url origin https://github.com/iamdevopser/onlynote.git
        Write-Host "Remote URL guncellendi." -ForegroundColor Green
    }
}
Write-Host ""

# Branch'i main yap
Write-Host "Branch main yapiliyor..." -ForegroundColor Yellow
git branch -M main 2>&1 | Out-Null
Write-Host "Branch main olarak ayarlandi." -ForegroundColor Green
Write-Host ""

# Push yap
Write-Host "GitHub'a push ediliyor..." -ForegroundColor Yellow
Write-Host "NOT: GitHub kimlik dogrulamasi gerekebilir." -ForegroundColor Cyan
Write-Host ""

try {
    git push -u origin main
    Write-Host ""
    Write-Host "=== BASARILI! ===" -ForegroundColor Green
    Write-Host "Dosyalar GitHub'a push edildi." -ForegroundColor Green
    Write-Host "Repository: https://github.com/iamdevopser/onlynote" -ForegroundColor Cyan
} catch {
    Write-Host ""
    Write-Host "=== HATA ===" -ForegroundColor Red
    Write-Host "Push islemi basarisiz oldu." -ForegroundColor Red
    Write-Host ""
    Write-Host "Olası nedenler:" -ForegroundColor Yellow
    Write-Host "1. GitHub kimlik dogrulamasi gerekli" -ForegroundColor Yellow
    Write-Host "2. Repository'de zaten kod var (pull yapmaniz gerekebilir)" -ForegroundColor Yellow
    Write-Host "3. Internet baglantisi sorunu" -ForegroundColor Yellow
    Write-Host ""
    Write-Host "Manuel push icin:" -ForegroundColor Cyan
    Write-Host "  git push -u origin main" -ForegroundColor White
}

