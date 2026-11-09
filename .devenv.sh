# Asteroid · entorno local de pruebas (afi-claude, skill «entorno-local»: devenv.sh asteroid-quasar start).
# Instala sin datos de ejemplo y carga los del Asteroid antiguo (volcado y archivos), con los usuarios de prueba
# kim 98 (admin), carles 101 y sergi 102 (equip), perepous 115 (client 1) y acolomer 118 (client 215), clave Prova1234.
# Otro volcado: ASTEROID_DUMP=/ruta/volcado.sql. Sin volcado, la instalación queda vacía.
# shellcheck shell=bash

DEVENV_INSTALL_ARGS="--no-demo"
DEVENV_LOGIN="kim@aficat.com"

devenv_config() {
  echo "    'app_name' => 'Asteroid',"
  echo "    'login_field' => 'email',"
  echo "    'api' => ['enabled' => true],"
}

devenv_seed() {
  local dump="${ASTEROID_DUMP:-$HOME/Projects/asteroid-27-10-25.sql}"
  if [ ! -f "$dump" ]; then
    echo "  Sin volcado ($dump): instalación vacía"
    return 0
  fi
  # Las vistas del volcado tienen como DEFINER este usuario
  M -e "CREATE DATABASE IF NOT EXISTS asteroid_old CHARACTER SET utf8mb4;
        CREATE USER IF NOT EXISTS 'asteroid_usr'@'localhost'; GRANT ALL ON asteroid_old.* TO 'asteroid_usr'@'localhost';"
  if [ "$(M -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='asteroid_old'")" = "0" ]; then
    M asteroid_old < "$dump"
  fi
  php tools/import-asteroid.php --host=127.0.0.1 --port="$DB_PORT" --name=asteroid_old --user="$DB_USER" --pass="$DB_PASS" \
    --files="$HOME/Projects/asteroid/admin/assets" --prefix=franky_ --admins=kim > "$BASE/import.log" 2>&1 || true
  tail -1 "$BASE/import.log"
  php tools/asteroid-migrate.php > /dev/null
  local hash
  hash=$(php -r 'echo password_hash("Prova1234", PASSWORD_DEFAULT);')
  M "$DB_NAME" -e "UPDATE users SET password_hash='$hash', active=1 WHERE id IN (98, 101, 102, 115, 118)"
}
