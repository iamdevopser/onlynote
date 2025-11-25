# Laravel Application Dockerfile
FROM php:8.2-fpm

# Set working directory
WORKDIR /var/www/html

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    nginx \
    supervisor \
    nodejs \
    npm \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# Install Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy application files
COPY . /var/www/html

# Create necessary directories if they don't exist and set permissions
RUN mkdir -p /var/www/html/storage /var/www/html/storage/framework/cache \
    /var/www/html/storage/framework/sessions /var/www/html/storage/framework/views \
    /var/www/html/storage/logs /var/www/html/bootstrap/cache \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/storage \
    && chmod -R 755 /var/www/html/bootstrap/cache

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction || true

# Build frontend assets (if package.json exists)
RUN if [ -f "package.json" ]; then \
    npm install && npm run build || true; \
    fi

# Copy nginx configuration (create directory if it doesn't exist)
RUN mkdir -p /etc/nginx/sites-available
COPY docker/nginx/default.conf /etc/nginx/sites-available/default 2>/dev/null || echo "server { listen 80; root /var/www/html/public; index index.php; location / { try_files \$uri \$uri/ /index.php?\$query_string; } location ~ \.php$ { fastcgi_pass 127.0.0.1:9000; fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name; include fastcgi_params; } }" > /etc/nginx/sites-available/default

# Copy supervisor configuration (create directory if it doesn't exist)
RUN mkdir -p /etc/supervisor/conf.d
COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf 2>/dev/null || echo "[supervisord]\nnodaemon=true\n[program:php-fpm]\ncommand=php-fpm\n[program:nginx]\ncommand=nginx -g 'daemon off;'" > /etc/supervisor/conf.d/supervisord.conf

# Expose port
EXPOSE 80

# Start supervisor
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]

