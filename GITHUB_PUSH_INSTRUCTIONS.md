# GitHub Push İşlemleri - WSL Terminal İçin Talimatlar

## Hızlı Başlangıç

WSL terminalinde şu komutları sırayla çalıştırın:

```bash
# 1. Proje dizinine gidin
cd "/mnt/c/Users/tarik/OneDrive/Masaüstü/projects/bootstrap/onlynote"

# Eğer yukarıdaki çalışmazsa:
cd "/mnt/c/Users/tarik/OneDrive/Desktop/projects/bootstrap/onlynote"

# 2. Git repository başlatın (eğer yoksa)
if [ ! -d .git ]; then git init; fi

# 3. Tüm değişiklikleri ekleyin
git add Dockerfile.app Dockerfile.mysql Dockerfile.redis deploy-aws-ecr.sh push-to-github.sh push-to-github.ps1 fix-line-endings.sh .gitignore AWS-ECR-DEPLOYMENT.md README.md docker/

# 4. Commit yapın
git commit -m "fix: Dockerfile.app storage dizinleri olusturma hatasi duzeltildi - Line ending fix scriptleri eklendi - GitHub push scriptleri eklendi"

# 5. Remote ayarlayın
git remote set-url origin https://github.com/iamdevopser/onlynote.git

# 6. Branch'i main yapın ve push edin
git branch -M main
git push -u origin main
```

## Tek Komutla (Kopyala-Yapıştır)

```bash
cd "/mnt/c/Users/tarik/OneDrive/Masaüstü/projects/bootstrap/onlynote" && \
if [ ! -d .git ]; then git init; fi && \
git add Dockerfile.app Dockerfile.mysql Dockerfile.redis deploy-aws-ecr.sh push-to-github.sh push-to-github.ps1 fix-line-endings.sh .gitignore AWS-ECR-DEPLOYMENT.md README.md docker/ && \
git commit -m "fix: Dockerfile.app storage dizinleri olusturma hatasi duzeltildi - Line ending fix scriptleri eklendi - GitHub push scriptleri eklendi" && \
git remote set-url origin https://github.com/iamdevopser/onlynote.git && \
git branch -M main && \
git push -u origin main
```

## Yapılan Değişiklikler

1. **Dockerfile.app**: Storage ve bootstrap/cache dizinlerinin oluşturulması eklendi
2. **fix-line-endings.sh**: Windows line ending sorunlarını düzelten script eklendi
3. **push-to-github.sh**: WSL/Linux için GitHub push script'i eklendi
4. **push-to-github.ps1**: Windows PowerShell için GitHub push script'i eklendi

## GitHub Kimlik Doğrulama

İlk push'ta kimlik doğrulaması gerekebilir:

- **Personal Access Token** kullanın (şifre yerine)
- Veya **SSH key** kullanın (önerilen)

