# Goetz Legal WordPress Site

## Project Structure

This project is a WordPress site using the Tailpress theme with Gutenberg editor capabilities. The structure is:

```
.
├── wp-content/
│   ├── themes/goetz-legal/
│   │   ├── package.json
│   │   ├── tsconfig.json
│   │   └── resources/
│   │       └── ts/
│   │           └── app.ts
│   └── plugins/
├── .bobo/
│   └── deploy.json
└── Dockerfile
```

## Prerequisites

- Docker engine
- Node.js 20+
- npm or yarn

## Install Commands

```bash
# Install Node.js dependencies
npm install

# Install WordPress dependencies
composer install
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

# Start production server
npm start
```

## Ports

- **Frontend**: 3000
- **WordPress Admin**: 8000

## Dockerfile

```dockerfile
FROM wordpress:6.4-php8.2-apache

# Install Node.js and npm
RUN apt-get update && apt-get install -y curl gnupg && \
    curl -fsSL https://deb.nodesource.com/setup_20.x | bash - && \
    apt-get install -y nodejs

# Copy WordPress files
COPY . /var/www/html

# Install Node.js dependencies
WORKDIR /var/www/html
RUN npm install

# Install WP-CLI
RUN curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && \
    chmod +x wp-cli.phar && \
    mv wp-cli.phar /usr/local/bin/wp

EXPOSE 80
CMD ["apache2-foreground"]
```

## BOBO Structured Deploy Contract

```bobo-deploy
{
  "version": 1,
  "app": { "port": 80, "health_path": "/" },
  "env": { },
  "services": [
    { "name": "mysql", "type": "mysql", "image": "mysql:8", "internal_port": "3306/tcp" }
  ],
  "handoff": {
    "frontend_url": "assigned by BOBO managed staging on port 80",
    "verification": ["GET / returns 200", "GET /wp-admin/ returns 200"]
  }
}
```