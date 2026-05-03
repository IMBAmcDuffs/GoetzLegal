# Goetz Legal WordPress Staging Environment

## Project Structure

```
.
├── wp-content/
│   ├── themes/goetz-legal/
│   │   ├── style.css
│   │   ├── functions.php
│   │   ├── package.json
│   │   ├── tsconfig.json
│   │   └── resources/
│   │       └── ts/
│   │           └── app.ts
│   └── plugins/
├── .bobo/
│   └── deploy.json
├── docker-compose.yml
├── Dockerfile
└── README.md
```

## Prerequisites

- Docker Engine v20.10+
- Docker Compose v2.0+
- Node.js v18+ (for theme development)
- npm v8+

## Install Commands

```bash
# Install WordPress dependencies
npm install

# Install PHP dependencies (if using composer)
composer install
```

## Build Commands

```bash
# Build theme assets
npm run build

# Build WordPress with plugins
npm run build:wp
```

## Run Commands

```bash
# Start development environment
npm run dev

# Start production environment
npm run start
```

## Ports

- **WordPress Frontend**: 3000
- **WordPress Admin**: 3000 (same port, different paths)
- **Database**: 3306 (via Docker service)

## Dockerfile

```dockerfile
FROM wordpress:6.7-php8.3-apache

# Install required PHP extensions
RUN docker-php-ext-install mysqli pdo_mysql

# Copy theme files
COPY wp-content/themes/goetz-legal /var/www/html/wp-content/themes/goetz-legal

# Copy plugins
COPY wp-content/plugins/ /var/www/html/wp-content/plugins/

# Install WP-CLI
RUN curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && \
    chmod +x wp-cli.phar && \
    mv wp-cli.phar /usr/local/bin/wp

# Set permissions
RUN chown -R www-data:www-data /var/www/html/wp-content/themes/goetz-legal

# Expose port
EXPOSE 80

# Health check
HEALTHCHECK --interval=30s --timeout=30s --start-period=5s --retries=3 \
  CMD curl -f http://localhost/wp-admin/install.php || exit 1
```

## BOBO Structured Deploy Contract

```bobo-deploy
{
  "version": 1,
  "app": {
    "port": 80,
    "health_path": "/wp-admin/install.php"
  },
  "env": {
    "WORDPRESS_DB_HOST": "{{service:mysql}}:3306",
    "WORDPRESS_DB_NAME": "wordpress",
    "WORDPRESS_DB_USER": "wp_user",
    "WORDPRESS_DB_PASSWORD": "wp_password"
  },
  "services": [
    {
      "name": "mysql",
      "type": "mariadb",
      "image": "mariadb:10.11",
      "internal_port": "3306/tcp",
      "env": {
        "MYSQL_ROOT_PASSWORD": "root_password",
        "MYSQL_DATABASE": "wordpress",
        "MYSQL_USER": "wp_user",
        "MYSQL_PASSWORD": "wp_password"
      }
    }
  ],
  "handoff": {
    "frontend_url": "assigned by BOBO managed staging on port 80",
    "verification": [
      "GET /wp-admin/install.php returns 200",
      "GET / returns 200",
      "WordPress core version check via WP-CLI"
    ]
  }
}
```