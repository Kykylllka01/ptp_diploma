#!/usr/bin/env bash
# Выкладка выпущенной версии на хостинг: scripts/deploy.sh v1.0.0
# Порядок выпуска версии — backend/docs/releasing.md. Адрес сервера и ключ — в scripts/deploy.env (не в git).
set -euo pipefail
cd "$(dirname "$0")/.."

die() { echo "Ошибка: $*" >&2; exit 1; }

TAG=${1:-}
[[ $TAG =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "укажите версию, например: scripts/deploy.sh v1.0.0"
[ -f scripts/deploy.env ] || die "нет scripts/deploy.env — скопируйте scripts/deploy.env.example и заполните"
# shellcheck source=/dev/null
source scripts/deploy.env
: "${DEPLOY_HOST:?}" "${DEPLOY_KEY:?}" "${DEPLOY_PATH:?}" "${DEPLOY_PHP:?}" "${DEPLOY_COMPOSER:?}"

git fetch -q --tags origin
COMMIT=$(git rev-parse -q --verify "refs/tags/$TAG^{commit}") || die "тега $TAG нет — сначала выпустите версию"
# Фронтенд собирается из рабочей копии, поэтому она должна совпадать с выпуском
[ "$(git rev-parse HEAD)" = "$COMMIT" ] || die "рабочая копия не на $TAG: git checkout main && git pull (выпуск — последний коммит main)"
git diff --quiet HEAD -- backend || die "в backend есть несохранённые изменения"
[ "$(git show "$TAG:backend/VERSION" | tr -d '\r\n')" = "${TAG#v}" ] || die "backend/VERSION в $TAG не равен ${TAG#v}"

SSH=(ssh -i "$DEPLOY_KEY" -o IdentitiesOnly=yes -o BatchMode=yes "$DEPLOY_HOST")

echo "== Сборка фронтенда"
(cd backend && PATH="${NODE_DIR:-}${NODE_DIR:+:}$PATH" node --max-old-space-size=512 node_modules/vite/bin/vite.js build >/dev/null)

echo "== Загрузка $TAG"
git archive --format=tar "$TAG:backend" | "${SSH[@]}" "tar -x -m -C '$DEPLOY_PATH'"
tar -cf - -C backend/public build | "${SSH[@]}" "tar -x -m -C '$DEPLOY_PATH/public'"

echo "== Зависимости, миграции, кэш"
"${SSH[@]}" "set -e; cd '$DEPLOY_PATH'
  '$DEPLOY_PHP' '$DEPLOY_COMPOSER' install --no-dev -o --no-interaction -q
  '$DEPLOY_PHP' artisan migrate --force
  '$DEPLOY_PHP' artisan optimize -q
  '$DEPLOY_PHP' artisan queue:restart -q
  '$DEPLOY_PHP' artisan pilot:check" || die "проверка после выкладки не прошла — смотрите таблицу выше"

echo "== Готово: на сервере $TAG"
