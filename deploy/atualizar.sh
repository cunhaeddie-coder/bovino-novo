#!/bin/bash
# Publica origin/main da API na homologação (homolog.bovino.agr.br, KVM4).
# Roda como deploy-bovino-lab, nunca root:
#   ssh sysadmin@187.127.51.199 'sudo -u deploy-bovino-lab bash /var/www/bovino-lab/api/current/deploy/atualizar.sh'
# Configuração inicial do servidor: bovino-novo-web/deploy/LEIA-ME.md.
#
# Sem queda: cada versão é montada numa pasta própria em releases/. O site só troca pra ela
# (symlink current) depois de composer, migrações e caches passarem. O nginx entrega ao PHP o
# caminho real ($realpath_root), então a troca vale a partir da próxima requisição; a que já
# estava rodando termina na versão anterior. Se algo falhar antes da troca, a anterior segue no ar.
set -euo pipefail

BASE=/var/www/bovino-lab/api
REPO=https://github.com/cunhaeddie-coder/bovino-novo.git
export HOME=/home/deploy-bovino-lab

exec 200>/tmp/bovino-lab-api-deploy.lock
flock -n 200 || { echo "Outro deploy da API em andamento. Abortando."; exit 1; }

REL="$BASE/releases/$(date +%Y%m%d-%H%M%S)"
echo "== nova versão em $REL"
git clone --quiet --depth 1 --branch main "$REPO" "$REL"
SHA=$(git -C "$REL" rev-parse --short HEAD)

cd "$REL"
rm -rf storage
ln -s "$BASE/shared/storage" storage
ln -s "$BASE/shared/.env" .env
composer install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet
php artisan migrate --force
php artisan config:cache
php artisan route:cache

# A versão nova precisa subir com a config e as rotas em cache antes da troca (o banco já
# respondeu nas migrações).
php artisan about --only=environment >/dev/null

echo "== trocando a API pra $SHA"
ln -sfn "$REL" "$BASE/current"
echo "$SHA" > "$BASE/deployed.txt"

# Pelo próprio servidor, mas em HTTPS: desde o certificado (27/10/2026) a porta 80 só redireciona (301).
code=$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 --resolve homolog.bovino.agr.br:443:127.0.0.1 https://homolog.bovino.agr.br/up || echo 000)
[ "$code" = "200" ] && echo "== no ar: $SHA" || echo "AVISO: /up respondeu $code depois da troca (anterior: ls $BASE/releases)"

# Mantém as 3 últimas versões pra voltar rápido: ln -sfn releases/<anterior> current.
ls -1dt "$BASE"/releases/* | tail -n +4 | xargs -r rm -rf
