# Define variables
PHP = php
COMPOSER = composer
PEST = vendor/bin/pest
PHP_CS_FIXER = vendor/bin/php-cs-fixer

# Directories
SRC_DIR = src
TESTS_DIR = tests

# Default target
.PHONY: all
all: install test lint

# Install dependencies
.PHONY: install
install:
	$(COMPOSER) install

# Run tests
.PHONY: test-all
test:
	$(PEST)

#Run test until defect
.PHONY: test
test:
	$(PEST) --stop-on-defect

# Check code quality with PHP-CS-Fixer (dry-run)
.PHONY: lint
lint:
	$(PHP_CS_FIXER) fix --config .php-cs-fixer.dist.php --dry-run --diff

# Automatically fix coding style issues with PHP-CS-Fixer
.PHONY: fix
fix:
	$(PHP_CS_FIXER) fix

# Clean up generated files
.PHONY: clean
clean:
	rm -rf vendor
	rm -rf var/cache

# Update dependencies
.PHONY: update
update:
	$(COMPOSER) update

# Show help message
.PHONY: help
help:
	@echo "Usage:"
	@echo "  make install   - Install dependencies"
	@echo "  make test      - Run tests"
	@echo "  make clean     - Clean up generated files"
	@echo "  make lint      - Check the coding standards"
	@echo "  make fix       - Fix all file with PHP-CS-Fixer coding standards"
	@echo "  make update    - Update dependencies"
