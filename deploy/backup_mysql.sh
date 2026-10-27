#!/bin/bash
# Backup diário do banco da homologação (bovino_lab). Roda como root, por /etc/cron.d/bovino-lab-backup.
# Receita da agência (CED Autos, 25/10/2026): lê as credenciais do .env, cifra com age, guarda 14 dias
# no servidor e envia ao Cloudflare R2 se /root/.r2-backup-bovino-lab.env existir. Falha cria
# BACKUP_FAILED; sucesso avisa o monitor da agência (rotina bovino-lab-backup).
# A chave privada do age não fica no servidor (está em _Agencia/segredos/ no disco F:).
set -euo pipefail

ENV_FILE="/var/www/bovino-lab/api/shared/.env"
BACKUP_DIR="/var/backups/bovino-lab"
RETAIN=14
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
LOG="$BACKUP_DIR/backup.log"

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

get_env() { grep -m1 "^$1=" "$ENV_FILE" | cut -d'=' -f2- | tr -d '"'"'"; }
log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG"; }

CREDS=$(mktemp)
chmod 600 "$CREDS"
trap 'rm -f "$CREDS"' EXIT
cat > "$CREDS" <<CNF
[client]
user=$(get_env DB_USERNAME)
password=$(get_env DB_PASSWORD)
host=$(get_env DB_HOST)
port=$(get_env DB_PORT)
CNF
DB=$(get_env DB_DATABASE)

AGE_PUBLIC_KEY=""
[ -f /root/.backup-encryption-bovino-lab.env ] && source /root/.backup-encryption-bovino-lab.env
if [ -z "$AGE_PUBLIC_KEY" ] || ! command -v age >/dev/null 2>&1; then
    log "ERRO — sem chave age ou sem o comando age: não faço backup sem cifra"
    touch "$BACKUP_DIR/BACKUP_FAILED"
    exit 1
fi

FILE="$BACKUP_DIR/bovino_lab_${TIMESTAMP}.sql.gz.age"
log "Iniciando — banco: $DB"
if mysqldump --defaults-extra-file="$CREDS" --single-transaction --no-tablespaces --routines --triggers "$DB" 2>/dev/null \
    | gzip | age -r "$AGE_PUBLIC_KEY" -o "$FILE"; then
    log "OK — $FILE ($(du -sh "$FILE" | cut -f1))"
    ls -t "$BACKUP_DIR"/bovino_lab_*.sql.gz.age | tail -n +$((RETAIN + 1)) | xargs -r rm -f
    rm -f "$BACKUP_DIR/BACKUP_FAILED"
else
    rm -f "$FILE"
    log "ERRO — backup falhou!"
    touch "$BACKUP_DIR/BACKUP_FAILED"
    exit 1
fi

R2_ENV="/root/.r2-backup-bovino-lab.env"
if [ -f "$R2_ENV" ]; then
    source "$R2_ENV"
    export RCLONE_CONFIG_R2_TYPE=s3 RCLONE_CONFIG_R2_PROVIDER=Cloudflare \
           RCLONE_CONFIG_R2_ACCESS_KEY_ID="$R2_BACKUP_ACCESS_KEY_ID" \
           RCLONE_CONFIG_R2_SECRET_ACCESS_KEY="$R2_BACKUP_SECRET_ACCESS_KEY" \
           RCLONE_CONFIG_R2_ENDPOINT="$R2_BACKUP_ENDPOINT" RCLONE_CONFIG_R2_NO_CHECK_BUCKET=true
    if rclone copy "$FILE" "r2:bovino-lab-backups/" --quiet 2>>"$LOG"; then
        log "Enviado para R2 (bovino-lab-backups)"
        rclone delete "r2:bovino-lab-backups/" --min-age 30d --quiet 2>>"$LOG" || true
    else
        log "AVISO: falha ao enviar para R2 (backup local OK)"
        touch "$BACKUP_DIR/BACKUP_FAILED"
        exit 1
    fi
else
    log "AVISO: R2 não configurado — backup só no servidor por enquanto"
fi

/usr/local/bin/monitor-batida bovino-lab-backup
log "Concluído."
