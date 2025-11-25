# AWS ECR Deployment Guide

Bu rehber, OnlyNote projesini AWS Container Registry (ECR) üzerinden deploy etmek için adım adım talimatlar içerir.

## Ön Gereksinimler

1. **AWS Hesabı**: Aktif bir AWS hesabınız olmalı
2. **AWS CLI**: Yüklü ve yapılandırılmış olmalı
   ```bash
   aws --version
   aws configure
   ```
3. **Docker**: Yüklü ve çalışır durumda olmalı
   ```bash
   docker --version
   ```
4. **Docker Compose**: Yüklü olmalı
   ```bash
   docker-compose --version
   ```

## Hızlı Başlangıç

### 1. Projeyi Klonlayın

```bash
git clone https://github.com/iamdevopser/onlynote.git
cd onlynote
```

### 2. Deployment Script'ini Çalıştırın

```bash
chmod +x deploy-aws-ecr.sh
./deploy-aws-ecr.sh
```

Script otomatik olarak:
- ✅ ECR repository oluşturur (yoksa)
- ✅ Docker image'ı build eder
- ✅ Image'ı ECR'ye push eder
- ✅ Docker Compose dosyası oluşturur
- ✅ Uygulamayı başlatır (isteğe bağlı)

## Manuel Adımlar

Eğer script kullanmak istemiyorsanız, aşağıdaki adımları manuel olarak takip edebilirsiniz:

### 1. ECR Repository Oluşturma

```bash
AWS_REGION="us-east-1"
ECR_REPOSITORY="onlynote"

aws ecr create-repository \
    --repository-name ${ECR_REPOSITORY} \
    --region ${AWS_REGION} \
    --image-scanning-configuration scanOnPush=true \
    --encryption-configuration encryptionType=AES256
```

### 2. ECR'ye Giriş Yapma

```bash
AWS_ACCOUNT_ID=$(aws sts get-caller-identity --query Account --output text)
ECR_REGISTRY="${AWS_ACCOUNT_ID}.dkr.ecr.${AWS_REGION}.amazonaws.com"

aws ecr get-login-password --region ${AWS_REGION} | \
    docker login --username AWS --password-stdin ${ECR_REGISTRY}
```

### 3. Docker Image Build Etme

```bash
docker build -f Dockerfile.free-tier -t ${ECR_REPOSITORY}:latest .
```

### 4. Image'ı Tag Etme ve Push Etme

```bash
docker tag ${ECR_REPOSITORY}:latest ${ECR_REGISTRY}/${ECR_REPOSITORY}:latest
docker push ${ECR_REGISTRY}/${ECR_REPOSITORY}:latest
```

### 5. Docker Compose ile Çalıştırma

```bash
# Environment variables ayarlayın
export ECR_REGISTRY="${AWS_ACCOUNT_ID}.dkr.ecr.${AWS_REGION}.amazonaws.com"
export ECR_REPOSITORY="onlynote"
export IMAGE_TAG="latest"

# docker-compose.ecr.yml dosyasını oluşturun (script bunu otomatik yapar)
# Sonra çalıştırın:
docker-compose -f docker-compose.ecr.yml up -d
```

## GitHub Actions ile Otomatik Deploy

GitHub Actions workflow'u, her `main` branch'e push'ta otomatik olarak ECR'ye image push eder.

### GitHub Secrets Ayarlama

1. GitHub repository'nize gidin
2. Settings → Secrets and variables → Actions
3. Aşağıdaki secrets'ları ekleyin:
   - `AWS_ACCESS_KEY_ID`: AWS access key ID
   - `AWS_SECRET_ACCESS_KEY`: AWS secret access key

### Workflow'u Tetikleme

- **Otomatik**: `main` branch'e push yaptığınızda otomatik çalışır
- **Manuel**: Actions sekmesinden "Deploy to AWS ECR" workflow'unu manuel olarak çalıştırabilirsiniz

## Environment Variables

Uygulamayı çalıştırmadan önce `.env` dosyasını oluşturun:

```bash
cp .env.example .env
php artisan key:generate
```

Gerekli environment variables:

```env
APP_NAME="OnlyNote"
APP_ENV=production
APP_DEBUG=false
APP_URL=http://localhost

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=onlynote
DB_USERNAME=onlynote_user
DB_PASSWORD=onlynote_password

REDIS_HOST=redis
REDIS_PORT=6379
CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
```

## Docker Compose Komutları

```bash
# Uygulamayı başlat
docker-compose -f docker-compose.ecr.yml up -d

# Logları görüntüle
docker-compose -f docker-compose.ecr.yml logs -f

# Uygulamayı durdur
docker-compose -f docker-compose.ecr.yml down

# Uygulamayı durdur ve volume'ları sil
docker-compose -f docker-compose.ecr.yml down -v

# Servisleri yeniden başlat
docker-compose -f docker-compose.ecr.yml restart
```

## ECR Image Yönetimi

### Image'ları Listeleme

```bash
aws ecr list-images \
    --repository-name onlynote \
    --region us-east-1
```

### Image Detaylarını Görüntüleme

```bash
aws ecr describe-images \
    --repository-name onlynote \
    --region us-east-1 \
    --image-ids imageTag=latest
```

### Eski Image'ları Silme

```bash
# Belirli bir tag'i sil
aws ecr batch-delete-image \
    --repository-name onlynote \
    --region us-east-1 \
    --image-ids imageTag=old-tag
```

## Sorun Giderme

### ECR Login Hatası

```bash
# AWS credentials kontrolü
aws sts get-caller-identity

# ECR login tekrar deneyin
aws ecr get-login-password --region us-east-1 | \
    docker login --username AWS --password-stdin ${ECR_REGISTRY}
```

### Docker Build Hatası

```bash
# Docker daemon çalışıyor mu kontrol edin
docker ps

# Build cache'i temizleyin
docker builder prune
```

### Image Pull Hatası

```bash
# ECR'ye login olduğunuzdan emin olun
aws ecr get-login-password --region us-east-1 | \
    docker login --username AWS --password-stdin ${ECR_REGISTRY}

# Image'ı manuel pull edin
docker pull ${ECR_REGISTRY}/onlynote:latest
```

## AWS Free Tier Limitleri

ECR Free Tier:
- **Storage**: 500 MB/ay
- **Data Transfer**: 500 MB/ay

Bu limitleri aşmamak için:
- Eski image'ları düzenli olarak silin
- Lifecycle policy kullanın (script otomatik ayarlar)
- Sadece gerekli image'ları tutun

## Güvenlik

1. **AWS Credentials**: Asla commit etmeyin
2. **ECR Policies**: Sadece gerekli IAM kullanıcılarına erişim verin
3. **Image Scanning**: ECR otomatik olarak image'ları tarar
4. **Secrets Management**: Production'da AWS Secrets Manager kullanın

## Destek

Sorun yaşarsanız:
1. GitHub Issues'da yeni bir issue açın
2. Logları kontrol edin: `docker-compose -f docker-compose.ecr.yml logs`
3. AWS CloudWatch Logs'u kontrol edin

## Ek Kaynaklar

- [AWS ECR Documentation](https://docs.aws.amazon.com/ecr/)
- [Docker Documentation](https://docs.docker.com/)
- [Docker Compose Documentation](https://docs.docker.com/compose/)

