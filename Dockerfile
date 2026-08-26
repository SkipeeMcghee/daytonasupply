# Use official PHP image
FROM php:8.2-cli

# Product uploads are normalized to Amazon-ready JPEGs by PHP GD.
RUN apt-get update \
	&& apt-get install -y --no-install-recommends libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev \
	&& docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
	&& docker-php-ext-install -j$(nproc) gd \
	&& rm -rf /var/lib/apt/lists/*

# Set working directory
WORKDIR /var/www/html

# Copy all files into the container
COPY . .

# Expose the port Render expects
EXPOSE 10000

# Start PHP's built-in server
CMD ["php", "-S", "0.0.0.0:10000"]