#!/bin/bash

# GitHub Push Script for OnlyNote Project (WSL/Linux)
# Bu script tüm değişiklikleri GitHub'a push eder

set -e

# Renkler
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

echo -e "${GREEN}=== GitHub Push Script (WSL) ===${NC}"
echo ""

# Proje dizini (WSL path)
PROJECT_PATH="/mnt/c/Users/tarik/OneDrive/Masaüstü/projects/bootstrap/onlynote"

# Windows path'i kontrol et
if [ ! -d "$PROJECT_PATH" ]; then
    echo -e "${RED}HATA: Proje dizini bulunamadi!${NC}"
    echo "Dizin: $PROJECT_PATH"
    echo ""
    echo "Alternatif dizinler kontrol ediliyor..."
    
    # Alternatif path'ler dene
    ALTERNATIVE_PATHS=(
        "/mnt/c/Users/tarik/OneDrive/Desktop/projects/bootstrap/onlynote"
        "/mnt/c/Users/tarik/OneDrive/Masaüstü/projects/bootstrap/onlynote"
        "$(pwd)"
    )
    
    for alt_path in "${ALTERNATIVE_PATHS[@]}"; do
        if [ -d "$alt_path" ] && [ -f "$alt_path/deploy-aws-ecr.sh" ]; then
            PROJECT_PATH="$alt_path"
            echo -e "${GREEN}Dizin bulundu: $PROJECT_PATH${NC}"
            break
        fi
    done
    
    if [ ! -d "$PROJECT_PATH" ]; then
        echo -e "${RED}Lutfen proje dizinine manuel olarak gidin:${NC}"
        echo "  cd /mnt/c/Users/tarik/OneDrive/Masaüstü/projects/bootstrap/onlynote"
        exit 1
    fi
fi

# Proje dizinine git
cd "$PROJECT_PATH"
echo -e "${YELLOW}Proje dizinine gidiliyor...${NC}"
echo "Dizin: $(pwd)"
echo ""

# Git repository kontrolü
if [ ! -d .git ]; then
    echo -e "${YELLOW}Git repository bulunamadi. Olusturuluyor...${NC}"
    git init
    echo -e "${GREEN}Git repository olusturuldu.${NC}"
else
    echo -e "${GREEN}Git repository bulundu.${NC}"
fi
echo ""

# Dosyaları kontrol et
echo -e "${YELLOW}Dosyalar kontrol ediliyor...${NC}"
FILES=(
    "deploy-aws-ecr.sh"
    "Dockerfile.app"
    "Dockerfile.mysql"
    "Dockerfile.redis"
    ".gitignore"
    "AWS-ECR-DEPLOYMENT.md"
    "README.md"
    "docker"
)

MISSING_FILES=()
for file in "${FILES[@]}"; do
    if [ -e "$file" ]; then
        echo -e "  ${GREEN}[OK]${NC} $file"
    else
        echo -e "  ${RED}[EKSIK]${NC} $file"
        MISSING_FILES+=("$file")
    fi
done
echo ""

if [ ${#MISSING_FILES[@]} -gt 0 ]; then
    echo -e "${YELLOW}UYARI: Bazı dosyalar bulunamadi:${NC}"
    for file in "${MISSING_FILES[@]}"; do
        echo -e "  - $file"
    done
    echo ""
fi

# Dosyaları git'e ekle
echo -e "${YELLOW}Dosyalar git'e ekleniyor...${NC}"
git add deploy-aws-ecr.sh \
    Dockerfile.app \
    Dockerfile.mysql \
    Dockerfile.redis \
    .gitignore \
    AWS-ECR-DEPLOYMENT.md \
    README.md \
    docker/ 2>/dev/null || true

echo -e "${GREEN}Dosyalar eklendi.${NC}"
echo ""

# Git durumunu göster
echo -e "${YELLOW}Git durumu:${NC}"
git status --short
echo ""

# Değişiklik var mı kontrol et
if git diff --cached --quiet && git diff --quiet; then
    echo -e "${YELLOW}UYARI: Commit edilecek degisiklik yok.${NC}"
    echo "Devam etmek istiyor musunuz? (y/n)"
    read -r response
    if [[ ! "$response" =~ ^[Yy]$ ]]; then
        echo "Islem iptal edildi."
        exit 0
    fi
fi

# Commit yap
echo -e "${YELLOW}Commit yapiliyor...${NC}"
COMMIT_MSG="feat: Microservice yapisi icin AWS ECR deployment scripti ve Dockerfilelar eklendi - AWS IAM hata yonetimi eklendi"

if git diff --cached --quiet; then
    echo -e "${YELLOW}UYARI: Staged degisiklik yok.${NC}"
else
    git commit -m "$COMMIT_MSG"
    echo -e "${GREEN}Commit yapildi.${NC}"
fi
echo ""

# Remote kontrolü
echo -e "${YELLOW}GitHub remote kontrol ediliyor...${NC}"
if git remote get-url origin &>/dev/null; then
    REMOTE_URL=$(git remote get-url origin)
    echo -e "${GREEN}Remote bulundu: $REMOTE_URL${NC}"
    
    if [[ "$REMOTE_URL" != *"iamdevopser/onlynote"* ]]; then
        echo -e "${YELLOW}Remote URL yanlis. Guncelleniyor...${NC}"
        git remote set-url origin https://github.com/iamdevopser/onlynote.git
        echo -e "${GREEN}Remote URL guncellendi.${NC}"
    fi
else
    echo -e "${YELLOW}Remote bulunamadi. Ekleniyor...${NC}"
    git remote add origin https://github.com/iamdevopser/onlynote.git
    echo -e "${GREEN}Remote eklendi.${NC}"
fi
echo ""

# Branch'i main yap
echo -e "${YELLOW}Branch main yapiliyor...${NC}"
CURRENT_BRANCH=$(git branch --show-current 2>/dev/null || echo "master")
if [ "$CURRENT_BRANCH" != "main" ]; then
    git branch -M main 2>/dev/null || true
    echo -e "${GREEN}Branch main olarak ayarlandi.${NC}"
else
    echo -e "${GREEN}Branch zaten main.${NC}"
fi
echo ""

# Push yap
echo -e "${YELLOW}GitHub'a push ediliyor...${NC}"
echo -e "${CYAN}NOT: GitHub kimlik dogrulamasi gerekebilir.${NC}"
echo ""

# Push işlemi
if git push -u origin main; then
    echo ""
    echo -e "${GREEN}=== BASARILI! ===${NC}"
    echo -e "${GREEN}Dosyalar GitHub'a push edildi.${NC}"
    echo -e "${CYAN}Repository: https://github.com/iamdevopser/onlynote${NC}"
    echo ""
else
    PUSH_EXIT_CODE=$?
    echo ""
    echo -e "${RED}=== HATA ===${NC}"
    echo -e "${RED}Push islemi basarisiz oldu.${NC}"
    echo ""
    echo -e "${YELLOW}Olası nedenler:${NC}"
    echo "1. GitHub kimlik dogrulamasi gerekli"
    echo "2. Repository'de zaten kod var (pull yapmaniz gerekebilir)"
    echo "3. Internet baglantisi sorunu"
    echo ""
    echo -e "${CYAN}Manuel push icin:${NC}"
    echo "  git push -u origin main"
    echo ""
    echo -e "${CYAN}Eger repository'de kod varsa once pull yapin:${NC}"
    echo "  git pull origin main --allow-unrelated-histories"
    echo "  git push -u origin main"
    echo ""
    exit $PUSH_EXIT_CODE
fi

