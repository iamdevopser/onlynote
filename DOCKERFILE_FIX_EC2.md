# EC2'de Dockerfile.app Düzeltme Talimatları

EC2 terminalinde şu komutları çalıştırın:

## Hızlı Düzeltme

```bash
cd ~/onlynote

# Dockerfile.app'i düzenleyin
nano Dockerfile.app
```

## Değiştirilecek Satırlar

### 1. Satır 42-43'ü Değiştirin (Composer Install)

**ESKİ:**
```dockerfile
# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction || true
```

**YENİ:**
```dockerfile
# Install PHP dependencies (only if composer.json exists)
RUN if [ -f "composer.json" ]; then \
    composer install --no-dev --optimize-autoloader --no-interaction || true; \
    fi
```

### 2. Satır 50-52'yi Değiştirin (Nginx Config)

**ESKİ:**
```dockerfile
# Copy nginx configuration (create directory if it doesn't exist)
RUN mkdir -p /etc/nginx/sites-available
COPY docker/nginx/default.conf /etc/nginx/sites-available/default 2>/dev/null || echo "server { listen 80; root /var/www/html/public; index index.php; location / { try_files \$uri \$uri/ /index.php?\$query_string; } location ~ \.php$ { fastcgi_pass 127.0.0.1:9000; fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name; include fastcgi_params; } }" > /etc/nginx/sites-available/default
```

**YENİ:**
```dockerfile
# Copy nginx configuration (create directory if it doesn't exist)
RUN mkdir -p /etc/nginx/sites-available
RUN if [ -f "docker/nginx/default.conf" ]; then \
    cp docker/nginx/default.conf /etc/nginx/sites-available/default; \
    else \
    echo 'server { listen 80; root /var/www/html/public; index index.php; location / { try_files $uri $uri/ /index.php?$query_string; } location ~ \.php$ { fastcgi_pass 127.0.0.1:9000; fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name; include fastcgi_params; } }' > /etc/nginx/sites-available/default; \
    fi
```

### 3. Satır 54-56'yı Değiştirin (Supervisor Config)

**ESKİ:**
```dockerfile
# Copy supervisor configuration (create directory if it doesn't exist)
RUN mkdir -p /etc/supervisor/conf.d
COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf 2>/dev/null || echo "[supervisord]\nnodaemon=true\n[program:php-fpm]\ncommand=php-fpm\n[program:nginx]\ncommand=nginx -g 'daemon off;'" > /etc/supervisor/conf.d/supervisord.conf
```

**YENİ:**
```dockerfile
# Copy supervisor configuration (create directory if it doesn't exist)
RUN mkdir -p /etc/supervisor/conf.d
RUN if [ -f "docker/supervisor/supervisord.conf" ]; then \
    cp docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf; \
    else \
    echo -e '[supervisord]\nnodaemon=true\n[program:php-fpm]\ncommand=php-fpm\n[program:nginx]\ncommand=nginx -g "daemon off;"' > /etc/supervisor/conf.d/supervisord.conf; \
    fi
```

## Kaydetme ve Çıkış

Nano editörde:
- `Ctrl + X` (Çıkış)
- `Y` (Evet, kaydet)
- `Enter` (Dosya adını onayla)

## Build'i Tekrar Deneyin

```bash
docker build -f Dockerfile.app -t onlynote-app:latest .
```


