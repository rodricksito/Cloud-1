#!/usr/bin/env bash
# Sincroniza sin borrar archivos remotos ni publicar secretos.
# Usa aliases SSH configurados localmente: cloud1-front/backend/db.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
SSH_CONFIG=${SSH_CONFIG:-"$ROOT/.local/ssh-config"}
SSH_CMD=(ssh)
if [[ -f $SSH_CONFIG ]]; then SSH_CMD+=(-F "$SSH_CONFIG"); fi
# Un wrapper evita problemas de comillas de rsync cuando la ruta contiene espacios.
REMOTE_SHELL=$(mktemp /tmp/cloud1-rsh.XXXXXX)
trap 'rm -f "$REMOTE_SHELL"' EXIT
export CLOUD1_SSH_CONFIG=$SSH_CONFIG
cat > "$REMOTE_SHELL" <<'RSH'
#!/usr/bin/env bash
if [[ -f ${CLOUD1_SSH_CONFIG:-} ]]; then
    exec ssh -F "$CLOUD1_SSH_CONFIG" "$@"
fi
exec ssh "$@"
RSH
chmod 700 "$REMOTE_SHELL"
for HOST in "${@:-}"; do
    [[ -n $HOST ]] || break
    rsync -az -e "$REMOTE_SHELL" --exclude=.git --exclude=.local --exclude=.env --exclude=config.local.php --exclude='*.key' --exclude='*.pem' \
      --rsync-path='sudo rsync' "$ROOT/" "$HOST:/var/www/cloud1/"
    "${SSH_CMD[@]}" "$HOST" 'sudo chown -R root:www-data /var/www/cloud1 && sudo chmod 640 /var/www/cloud1/config.local.php'
done
if [[ $# -eq 0 ]]; then
    echo 'Uso: bash deploy/sync.sh cloud1-front cloud1-backend cloud1-db' >&2
    exit 2
fi
