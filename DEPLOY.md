# Goetz Legal WordPress Site

## Project Structure

This repository contains a WordPress site with:
- `wp-content/` - WordPress core and plugin files
- `wp-content/themes/goetz-legal/` - Custom Tailpress theme
- `wp-content/plugins/` - WordPress plugins directory

## Prerequisites

- Docker engine
- Docker Compose
- Node.js (for theme development)

## Install Commands

```bash
# Install WordPress dependencies
npm install

# Install theme dependencies
cd wp-content/themes/goetz-legal
npm install
```

## Build Commands

```bash
# Build theme assets
npm run build
```

## Run Commands

```bash
# Start development environment
docker-compose up
```

## Ports

- WordPress: 8080
- PHPMyAdmin: 8081

## Dockerfile

```dockerfile
FROM wordpress:6.4-php8.2-apache

# Install required PHP extensions
RUN docker-php-ext-install mysqli pdo_mysql

# Install WP-CLI
RUN curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && \
    chmod +x wp-cli.phar && \
    mv wp-cli.phar /usr/local/bin/wp

# Copy WordPress configuration
COPY ./wp-config.php /var/www/html/wp-config.php

# Copy plugins
COPY ./wp-content/plugins/ /var/www/html/wp-content/plugins/

# Copy theme
COPY ./wp-content/themes/goetz-legal/ /var/www/html/wp-content/themes/goetz-legal/

# Install and activate required plugins
RUN wp plugin install yoast-seo --activate --allow-root --path=/var/www/html && \
    wp plugin install forminator --activate --allow-root --path=/var/www/html

EXPOSE 80
```

## BOBO Structured Deploy Contract

```bobo-deploy
{
  "version": 1,
  "app": { "port": 80, "health_path": "/wp-admin/" },
  "env": { "WP_ENV": "development" },
  "services": [
    { "name": "mysql", "type": "mysql", "image": "mysql:8", "internal_port": "3306/tcp" }
  ],
  "handoff": {
    "frontend_url": "assigned by BOBO managed staging on port 80",
    "verification": ["GET /wp-admin/ returns 200", "wp plugin list shows yoast-seo and forminator installed"]
  }
}
```