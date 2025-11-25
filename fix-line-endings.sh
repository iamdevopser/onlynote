#!/bin/bash
# Line ending düzeltme script'i
# Bu script Windows'tan Linux'a aktarılan dosyaların line ending'lerini düzeltir

echo "Line ending'ler düzeltiliyor..."

# dos2unix varsa kullan
if command -v dos2unix &> /dev/null; then
    echo "dos2unix kullaniliyor..."
    dos2unix deploy-aws-ecr.sh
    dos2unix push-to-github.sh
    echo "Tamamlandi!"
else
    echo "dos2unix bulunamadi. sed ile düzeltiliyor..."
    # sed ile \r karakterlerini kaldır
    sed -i 's/\r$//' deploy-aws-ecr.sh
    sed -i 's/\r$//' push-to-github.sh
    echo "Tamamlandi!"
fi

# Script'leri çalıştırılabilir yap
chmod +x deploy-aws-ecr.sh
chmod +x push-to-github.sh

echo "Script'ler hazir!"

