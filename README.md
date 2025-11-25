# 🎓 OnliNote LMS Platform

Kapsamlı bir Learning Management System (LMS) platformu. Kullanıcılar kurslara kaydolabilir, eğitmenler kurs oluşturabilir, adminler sistemi yönetebilir.

## ✨ Özellikler

### 🎯 Temel Özellikler
- ✅ Kullanıcı, eğitmen ve admin panelleri
- ✅ Kurs yönetimi ve satışı
- ✅ Kategori ve alt kategori yönetimi
- ✅ Sepet ve ödeme sistemi
- ✅ Kupon yönetimi
- ✅ Yorum ve değerlendirme sistemi
- ✅ Slider ve bilgi kutuları
- ✅ Quiz ve ödev sistemi
- ✅ Sertifika oluşturma

### 💳 Ödeme Sistemi
- ✅ Stripe entegrasyonu
- ✅ Abonelik yönetimi
- ✅ Ödeme geçmişi
- ✅ Fatura yönetimi

### 🚀 Teknik Özellikler
- ✅ Laravel 11
- ✅ PHP 8.2
- ✅ MySQL 8.0
- ✅ Redis Cache
- ✅ Docker desteği
- ✅ AWS Free Tier deployment
- ✅ Responsive tasarım
- ✅ Modern UI/UX

## 📋 Gereksinimler

- PHP >= 8.2
- Composer
- Node.js >= 18 & npm
- MySQL >= 8.0
- Redis >= 7.0
- Docker (opsiyonel)

## 🛠️ Kurulum

### Docker ile Kurulum (Önerilen)

```bash
# Repository'yi klonla
git clone https://github.com/your-username/lms-platform.git
cd lms-platform

# Environment dosyasını oluştur
cp docker.env.example .env

# Docker Compose ile başlat
docker-compose -f docker-compose.dev.yml up -d

# Migration ve seeder çalıştır
docker-compose -f docker-compose.dev.yml exec app php artisan migrate --force
docker-compose -f docker-compose.dev.yml exec app php artisan db:seed --force
docker-compose -f docker-compose.dev.yml exec app php artisan storage:link

# Uygulamaya eriş
# http://localhost:8000
```

### Manuel Kurulum

```bash
# Bağımlılıkları yükle
composer install
npm install

# Environment dosyasını oluştur
cp .env.example .env
php artisan key:generate

# Veritabanı yapılandırması
# .env dosyasında DB ayarlarını yapın

# Migration ve seeder
php artisan migrate
php artisan db:seed

# Storage link
php artisan storage:link

# Frontend build
npm run build

# Uygulamayı başlat
php artisan serve
```

## ☁️ AWS Free Tier Deployment

Tamamen ücretsiz AWS Free Tier deployment için:

```bash
cd aws
chmod +x deploy-free-simple.sh
./deploy-free-simple.sh deploy
```

**Maliyet: $0** (Free Tier kaynakları kullanılır)

Detaylı rehber için [AWS-FREE-DEPLOYMENT.md](AWS-FREE-DEPLOYMENT.md) dosyasına bakın.

## 📚 Dokümantasyon

- [AWS Free Tier Deployment Guide](AWS-FREE-DEPLOYMENT.md) - AWS kurulum rehberi
- [Quick Start Guide](QUICK-START-FREE.md) - Hızlı başlangıç
- [Docker Setup](DOCKER-README.md) - Docker kurulumu
- [GitHub Setup Guide](GITHUB-SETUP.md) - GitHub'a yükleme rehberi

## 🗂️ Proje Yapısı

```
lms-platform/
├── app/
│   ├── Http/Controllers/    # Controller'lar
│   ├── Models/              # Eloquent Modeller
│   ├── Services/            # Business Logic
│   ├── Repositories/        # Data Access Layer
│   └── Mail/                # Email Templates
├── database/
│   ├── migrations/          # Database Migrations
│   └── seeders/             # Database Seeders
├── resources/
│   ├── views/               # Blade Templates
│   ├── css/                 # CSS Dosyaları
│   └── js/                  # JavaScript Dosyaları
├── routes/
│   ├── web.php              # Web Routes
│   └── auth.php             # Authentication Routes
├── public/                  # Public Assets
├── aws/                     # AWS Deployment Scripts
└── docker/                  # Docker Configurations
```

## 🔐 Varsayılan Kullanıcılar

Seeder çalıştırdıktan sonra aşağıdaki kullanıcılar oluşturulur:

- **Admin**: admin@example.com / password
- **Instructor**: instructor@example.com / password
- **User**: user@example.com / password

⚠️ **Önemli**: Production'da bu kullanıcıları değiştirin!

## 🧪 Test

```bash
# Test çalıştır
php artisan test

# Coverage ile test
php artisan test --coverage
```

## 🤝 Katkıda Bulunma

1. Fork edin
2. Feature branch oluşturun (`git checkout -b feature/AmazingFeature`)
3. Commit edin (`git commit -m 'Add some AmazingFeature'`)
4. Push edin (`git push origin feature/AmazingFeature`)
5. Pull Request oluşturun

## 📝 Changelog

Tüm önemli değişiklikler [CHANGELOG.md](CHANGELOG.md) dosyasında belgelenmiştir.

## 🐛 Sorun Bildirimi

Sorun bulursanız lütfen [Issues](https://github.com/your-username/lms-platform/issues) sayfasında bildirin.

## 💡 Özellik İsteği

Yeni özellik önerileri için [Issues](https://github.com/your-username/lms-platform/issues) sayfasında feature request oluşturun.

## 📄 Lisans

Bu proje [MIT License](LICENSE) altında lisanslanmıştır.

## 👥 Yazarlar

- **Your Name** - [GitHub](https://github.com/your-username)

## 🙏 Teşekkürler

- [Laravel](https://laravel.com) - PHP Framework
- [Stripe](https://stripe.com) - Payment Processing
- [AWS](https://aws.amazon.com) - Cloud Infrastructure
- [Docker](https://www.docker.com) - Containerization
- Tüm açık kaynak kütüphane geliştiricileri

## 🔗 Bağlantılar

- [Documentation](https://github.com/your-username/lms-platform/wiki)
- [Issues](https://github.com/your-username/lms-platform/issues)
- [Releases](https://github.com/your-username/lms-platform/releases)

## 📊 Proje İstatistikleri

![GitHub stars](https://img.shields.io/github/stars/your-username/lms-platform?style=social)
![GitHub forks](https://img.shields.io/github/forks/your-username/lms-platform?style=social)
![GitHub issues](https://img.shields.io/github/issues/your-username/lms-platform)
![GitHub license](https://img.shields.io/github/license/your-username/lms-platform)

---

⭐ Bu projeyi beğendiyseniz yıldız vermeyi unutmayın!
