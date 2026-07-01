ROOT := $(shell pwd)

b:
	docker compose build
u: # запуск контейнеров
	$(eval IP := $(shell hostname -I | awk '{print $$1}'))
	$(eval VER := $(shell awk 'NR==1{for(i=1;i<=NF;i++) if($$i ~ /^v[0-9]/){print $$i; exit}}' version))
	@if [ -d .git ]; then \
		branch=$$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo master); \
		git fetch origin $$branch --quiet || git fetch origin --quiet; \
		git checkout origin/$$branch -- app update makefile version || true; \
	fi
	bash ./update/update.sh &
	bash ./scripts/bootstrap_config.sh
	touch ./override.env ./docker-compose.override.yml ./config/location.conf ./config/override.conf
	IP=$(IP) VER=$(VER) docker compose --env-file ./.env --env-file ./override.env up -d --force-recreate
d: # остановка контейнеров
	-kill -9 $(shell cat ./update/update_pid) > /dev/null
	docker compose down --remove-orphans
dv: # остановка контейнеров
	docker compose down -v
r: d u
ps: # список контейнеров
	docker compose ps
l: # логи из контейнеров
	docker compose logs
php: # консоль сервиса
	docker compose exec php /bin/sh
wg: # консоль сервиса
	docker compose exec wg /bin/sh
wg1: # консоль сервиса
	docker compose exec wg1 /bin/sh
ng: # консоль сервиса
	docker compose exec ng /bin/sh
ad: # консоль сервиса
	docker compose exec ad /bin/sh
wp: # консоль сервиса
	docker compose exec wp bash
tg: # консоль сервиса
	docker compose exec tg /bin/sh
hy: # консоль сервиса
	docker compose exec hy /bin/sh
xr: # консоль сервиса
	docker compose exec xr /bin/sh
service: # консоль сервиса
	docker compose exec service /bin/sh
delete:
	make d
	docker system prune -f -a
	docker volume prune -f -a
	rm -rf /root/vpnbot
push:
	docker compose push
s:
	git status -su
webhook:
	docker compose exec php php checkwebhook.php
reset:
	make d
	git reset --hard
	git clean -fd
	docker volume rm vpnbot_adguard vpnbot_warp
	make u
backup:
	docker compose exec php php backup.php > backup.json
smoke:
	bash ./scripts/smoke_check.sh
cron: # установка задачи в cron для автозапуска при перезагрузке
	@(crontab -l 2>/dev/null | grep -v "cd $(ROOT) && make r"; echo "@reboot cd $(ROOT) && make r") | crontab -
uncron: # удаление задачи из cron
	@crontab -l 2>/dev/null | grep -v "cd $(ROOT) && make r" | crontab -