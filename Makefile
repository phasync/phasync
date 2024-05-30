# Define variables
PHP = php
COMPOSER = composer
PEST = vendor/bin/pest

# Default target
.PHONY: all
all: install test

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
	@echo "  make update    - Update dependencies"
