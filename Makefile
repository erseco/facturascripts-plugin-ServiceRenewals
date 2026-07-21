# Makefile para facilitar el uso de Docker en el desarrollo del plugin ServiceRenewals

.PHONY: help up upd down pull build shell clean package enable-plugin rebuild cron lint format test logs ps fresh check-docker

# Define SED_INPLACE según el sistema operativo
ifeq ($(shell uname), Darwin)
  SED_INPLACE = sed -i ''
else
  SED_INPLACE = sed -i
endif

# Detecta el sistema operativo
ifeq ($(OS),Windows_NT)
    ifdef MSYSTEM
        SYSTEM_OS := unix
    else ifdef CYGWIN
        SYSTEM_OS := unix
    else
        SYSTEM_OS := windows
    endif
else
    SYSTEM_OS := unix
endif

# Comprueba que Docker está en marcha
check-docker:
ifeq ($(SYSTEM_OS),windows)
	@echo "Detected system: Windows (cmd, powershell)"
	@docker version > NUL 2>&1 || (echo. & echo Error: Docker is not running. Please make sure Docker is installed and running. & echo. & exit 1)
else
	@echo "Detected system: Unix (Linux/macOS/Cygwin/MinGW)"
	@docker version > /dev/null 2>&1 || (echo "" && echo "Error: Docker is not running. Please make sure Docker is installed and running." && echo "" && exit 1)
endif

# Arranca los contenedores en modo interactivo
up: check-docker
	docker compose up --remove-orphans

# Arranca los contenedores en segundo plano
upd: check-docker
	docker compose up --detach --remove-orphans

# Para y elimina los contenedores
down: check-docker
	docker compose down

# Descarga las últimas imágenes del registro
pull: check-docker
	docker compose -f docker-compose.yml pull

# Construye o reconstruye los contenedores
build: check-docker
	docker compose build

# Abre una shell dentro del contenedor de FacturaScripts
shell: check-docker
	docker compose exec facturascripts sh

# Para los contenedores y elimina volúmenes y huérfanos
clean: check-docker
	docker compose down -v --remove-orphans

# Genera el paquete ServiceRenewals-X.zip
package:
	@if [ -z "$(VERSION)" ]; then \
		echo "Error: VERSION not specified. Use 'make package VERSION=1'"; \
		exit 1; \
	fi
	@echo "Updating version to $(VERSION) in facturascripts.ini..."
	$(SED_INPLACE) 's/^\(version[[:space:]]*=[[:space:]]*\).*$$/\1$(VERSION)/' facturascripts.ini
	@echo "Creating ZIP archive: ServiceRenewals-$(VERSION).zip..."
	@mkdir -p dist
	@rm -rf dist/build
	@mkdir -p dist/build/ServiceRenewals
	@rsync -a --exclude '.git' --exclude '.github' --exclude '.agents' --exclude 'dist' \
		--exclude 'docs' --exclude 'docker' --exclude 'Test' --exclude 'vendor' \
		--exclude 'node_modules' --exclude '.DS_Store' --exclude 'Makefile' \
		--exclude 'docker-compose.yml' --exclude 'docker-compose.override.yml' \
		--exclude 'blueprint.json' --exclude 'phpcs.xml' --exclude '.php-cs-fixer.php' \
		--exclude '.php-cs-fixer.cache' --exclude '.phpunit.result.cache' \
		--exclude '.gitattributes' --exclude '.gitignore' --exclude '.omc' --exclude '.claude' \
		--exclude '*.md' --exclude '*.zip' \
		./ dist/build/ServiceRenewals/
	@cd dist/build && zip -rq ../ServiceRenewals-$(VERSION).zip ServiceRenewals
	@rm -rf dist/build
	@echo "Restoring version in facturascripts.ini..."
	$(SED_INPLACE) 's/^\(version[[:space:]]*=[[:space:]]*\).*$$/\11.0/' facturascripts.ini
	@echo "Package created: dist/ServiceRenewals-$(VERSION).zip"

# Activa el plugin en FacturaScripts
enable-plugin: check-docker
	@echo "Enabling ServiceRenewals plugin..."
	@docker compose exec facturascripts sh -c "cd /var/www/html && php84 index.php"
	@echo "Plugin enabled! Access FacturaScripts at http://localhost:8080"
	@echo "Login with admin/admin"

# Reconstruye las clases dinámicas de FacturaScripts
rebuild: check-docker
	@echo "Rebuilding FacturaScripts..."
	@docker compose exec facturascripts sh -c "curl -s http://localhost:8080/deploy?action=rebuild > /dev/null"
	@echo "Rebuild complete!"

# Ejecuta el cron de FacturaScripts una vez (procesa renovaciones y cola de trabajos).
# El contenedor también lo ejecuta cada hora; usa este comando para procesar bajo demanda.
cron: check-docker
	@echo "Running the FacturaScripts cron..."
	@docker compose exec facturascripts sh -c "cd /var/www/html && php84 index.php -cron"
	@echo "Cron processed. Check Mailpit at http://localhost:8025"

# Ejecuta PHP CodeSniffer para comprobar el estilo del código
lint: check-docker upd
	@echo "Running PHP CodeSniffer..."
	@echo ""
	@docker compose exec facturascripts sh -c 'cd /var/www/html && echo "→ Installing phpcs if needed..." && if [ ! -f vendor/bin/phpcs ]; then php84 /usr/local/bin/composer require --dev squizlabs/php_codesniffer --no-interaction; fi'
	@docker compose exec facturascripts sh -c 'cd /var/www/html && php84 vendor/bin/phpcs --standard=Plugins/ServiceRenewals/phpcs.xml Plugins/ServiceRenewals --colors'
	@echo ""
	@echo "✅ Lint check completed!"

# Ejecuta PHP CS Fixer para corregir el estilo automáticamente
format: check-docker upd
	@echo "Running PHP CS Fixer..."
	@echo ""
	@docker compose exec facturascripts sh -c 'cd /var/www/html && echo "→ Installing php-cs-fixer if needed..." && if [ ! -f vendor/bin/php-cs-fixer ]; then php84 /usr/local/bin/composer require --dev friendsofphp/php-cs-fixer --no-interaction; fi'
	@docker compose exec facturascripts sh -c 'cd /var/www/html/Plugins/ServiceRenewals && php84 /var/www/html/vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php --verbose'
	@echo ""
	@echo "✅ Code formatting completed!"

# Ejecuta los tests dentro del contenedor
test: check-docker upd
	@echo "Running unit tests..."
	@echo ""
	@docker compose exec facturascripts sh -c 'cd /var/www/html && echo "→ Installing PHPUnit if needed..." && if [ ! -f vendor/bin/phpunit ]; then php84 /usr/local/bin/composer require --dev phpunit/phpunit --no-interaction; fi'
	@docker compose exec facturascripts sh -c 'cd /var/www/html && echo "→ Setting up test environment..." && mkdir -p Test/Plugins && cp -r Plugins/ServiceRenewals/Test/main/* Test/Plugins/ 2>/dev/null || true && cp Plugins/ServiceRenewals/Test/bootstrap.php Test/bootstrap.php 2>/dev/null || true && cp Plugins/ServiceRenewals/Test/install-plugins.php Test/install-plugins.php 2>/dev/null || true'
	@docker compose exec facturascripts sh -c 'cd /var/www/html && test -f Test/Plugins/install-plugins.txt || (echo "❌ Error: No tests found in Test/main/" && exit 1)'
	@docker compose exec facturascripts sh -c 'cd /var/www/html && echo "→ Installing test plugins..." && php84 Test/install-plugins.php'
	@docker compose exec facturascripts sh -c 'cd /var/www/html && test -f phpunit-plugins.xml || echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?><phpunit bootstrap=\"Test/bootstrap.php\" colors=\"true\"><testsuites><testsuite name=\"PluginTests\"><directory>Test/Plugins</directory></testsuite></testsuites></phpunit>" > phpunit-plugins.xml'
	@echo "→ Running PHPUnit tests..."
	@echo ""
	@docker compose exec facturascripts sh -c 'cd /var/www/html && php84 vendor/bin/phpunit -c phpunit-plugins.xml'
	@echo ""
	@echo "✅ Tests completed!"

# Muestra los logs
logs:
	docker compose logs -f --tail=200

# Muestra el estado de los contenedores
ps:
	docker compose ps

# Arranque desde cero (limpia volúmenes y arranca)
fresh: clean upd

# Muestra la ayuda con los comandos disponibles
help:
	@echo ""
	@echo "Usage: make <command>"
	@echo ""
	@echo "Docker management:"
	@echo "  up                - Start containers in interactive mode"
	@echo "  upd               - Start containers in background mode (detached)"
	@echo "  down              - Stop and remove containers"
	@echo "  build             - Build or rebuild containers"
	@echo "  pull              - Pull the latest images from the registry"
	@echo "  clean             - Stop containers and remove volumes"
	@echo "  fresh             - Clean volumes and start again (fresh DB)"
	@echo "  shell             - Open a shell inside the facturascripts container"
	@echo "  logs              - Tail container logs"
	@echo "  ps                - Show container status"
	@echo ""
	@echo "Code Quality:"
	@echo "  lint              - Run PHP CodeSniffer to check code style"
	@echo "  format            - Run PHP CS Fixer to automatically fix code style"
	@echo ""
	@echo "Testing:"
	@echo "  test              - Run unit tests inside container"
	@echo ""
	@echo "Plugin management:"
	@echo "  enable-plugin     - Enable the plugin in FacturaScripts"
	@echo "  rebuild           - Rebuild FacturaScripts dynamic classes"
	@echo "  cron              - Run the FacturaScripts cron once (process renewals)"
	@echo ""
	@echo "Packaging:"
	@echo "  package           - Generate a .zip package of the plugin with version tag"
	@echo "                      Usage: make package VERSION=1"
	@echo ""
	@echo "Other:"
	@echo "  help              - Show this help message"
	@echo ""

# Objetivo por defecto
.DEFAULT_GOAL := help
