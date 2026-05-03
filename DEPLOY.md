# Goetz Legal WordPress Theme Deployment

## Project Structure

This project is a WordPress theme built with TailPress and Tailwind CSS. The theme files are located in `wp-content/themes/goetz-legal/`.

## Prerequisites

- Node.js 20+ (for development)
- PHP 7.4+ (for WordPress)
- MySQL 5.7+ (for WordPress database)

## Install Commands

```bash
# Install theme dependencies
npm install

# Install WordPress dependencies
wp core download --path=/var/www/html
wp config create --dbname=wordpress --dbuser=root --dbpass=password --dbhost=mysql
wp core install --url=http://localhost --title=GoetzLegal --admin_user=admin --admin_password=password --admin_email=admin@example.com
```

## Build Commands

```bash
# Build theme assets
npm run build
```

## Run Commands

```bash
# Start development server
npm run dev

# For production, WordPress handles serving
```

## Ports

- 3000: Development server (Vite)
- 80: WordPress web server

## Dockerfile

```dockerfile
FROM wordpress:6.4-php8.2

# Install Node.js for theme building
RUN apt-get update && apt-get install -y curl gnupg && \
    curl -fsSL https://deb.nodesource.com/setup_20.x | bash - && \
    apt-get install -y nodejs

# Copy theme files
COPY wp-content/themes/goetz-legal/ /var/www/html/wp-content/themes/goetz-legal/

# Install theme dependencies
RUN cd /var/www/html/wp-content/themes/goetz-legal && npm install

# Build theme assets
RUN cd /var/www/html/wp-content/themes/goetz-legal && npm run build

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html/wp-content/themes/goetz-legal

EXPOSE 80
```

## BOBO Structured Deploy Contract

```bobo-deploy
{
  "version": 1,
  "app": {
    "port": 80,
    "health_path": "/wp-admin/",
    "docs_path": "/wp-admin/"
  },
  "env": {
    "WP_ENV": "production",
    "DB_HOST": "{{service:mysql}}:3306",
    "DB_NAME": "wordpress",
    "DB_USER": "wordpress",
    "DB_PASSWORD": "password"
  },
  "services": [
    {
      "name": "mysql",
      "type": "mysql",
      "image": "mysql:8",
      "internal_port": "3306/tcp",
      "env": {
        "MYSQL_ROOT_PASSWORD": "password",
        "MYSQL_DATABASE": "wordpress",
        "MYSQL_USER": "wordpress",
        "MYSQL_PASSWORD": "password"
      }
    }
  ],
  "handoff": {
    "frontend_url": "assigned by BOBO managed staging on port 80",
    "verification": [
      "GET / returns 200",
      "GET /wp-admin/ returns 200",
      "Theme is active and functional"
    ]
  }
}
```