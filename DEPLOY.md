# Развёртывание на сервере с нуля

Пошаговый сценарий от пустого VPS до работающего бота. README описывает приложение;
здесь — то, что нужно сделать с самим сервером.

Проверено на Debian 13. Для Ubuntu 24.04 LTS отличается только установка PHP (шаг 3).

---

## Выбор сервера

**Тип: обычный VPS.** Не шаред-хостинг и не бессерверная платформа, и вот почему:

- нужен PHP 8.4+ и правка `php.ini` — на шаред-хостинге обычно недоступно;
- нужен собственный крон;
- база SQLite — это локальный файл, и на нём держится вся защита от потери документов.
  Платформа без постоянного диска (или с диском, который пересоздаётся при деплое)
  сотрёт очередь необработанных файлов и OAuth-токены портала.

**Ресурсы.** Нагрузка — редкие вебхуки и один крон раз в 5 минут. Достаточно
**1 vCPU, 1 ГБ RAM, 20 ГБ SSD**. Больше брать незачем; 2 ГБ RAM имеет смысл, только
если на том же сервере будет что-то ещё.

**Один экземпляр, без балансировщика.** SQLite и механизм аренды строк очереди
рассчитаны на один процесс-владелец файла базы. Два сервера за балансировщиком
с общей сетевой ФС сломают и то, и другое.

**Расположение.** Портал `.bitrix24.ru` работает с восточным OAuth-сервером
(`oauth.bitrix24.tech`), и вебхуки к вам идут с инфраструктуры Битрикс24. Для такого
портала берите российского провайдера — Timeweb Cloud, Selectel, Yandex Cloud, REG.RU,
Beget. Главное требование к площадке: сервер доступен из интернета по HTTPS на 443
и имеет публичный домен.

**Домен и сертификат обязательны.** Битрикс24 не станет отправлять события на
самоподписанный сертификат и на IP-адрес без домена. Нужна A-запись на ваш сервер
и валидный сертификат — ниже это Let's Encrypt.

## Выбор ОС

**Debian 13 (trixie)** — рекомендую. PHP 8.4 есть в базовом репозитории, сторонние
репозитории не нужны, обновления безопасности приходят штатно.

**Ubuntu 24.04 LTS** — равноценная альтернатива, если вы к ней привыкли, но PHP 8.4
придётся ставить из PPA `ondrej/php`: в базовом репозитории 24.04 лежит 8.3, а SDK
требует именно `8.4.* || 8.5.*`.

Перед установкой проверьте, что нужная версия действительно есть:

```bash
apt-cache policy php8.4-cli
```

Если строка `Candidate` пустая — вы на дистрибутиве без PHP 8.4 в репозиториях,
переходите к варианту с PPA (шаг 3б).

---

## Шаг 1. Домен и первый вход

Создайте VPS, получите его IP. В DNS заведите A-запись, например
`bot.example.org → <IP>`, и дождитесь, пока она разойдётся:

```bash
dig +short bot.example.org
```

Пока запись не отвечает вашим IP, выпуск сертификата на шаге 8 работать не будет.

## Шаг 2. Базовая настройка сервера

Под root на новом сервере:

```bash
apt update && apt upgrade -y
adduser deploy
usermod -aG sudo deploy
```

Скопируйте свой SSH-ключ пользователю `deploy` (с локальной машины):

```bash
ssh-copy-id deploy@bot.example.org
```

Проверьте, что вход по ключу работает, и только потом отключайте вход по паролю
в `/etc/ssh/sshd_config`:

```
PasswordAuthentication no
PermitRootLogin no
```

```bash
systemctl restart ssh
```

Файрвол:

```bash
apt install -y ufw
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw enable
```

Порт 80 нужен: по нему Let's Encrypt проверяет владение доменом.

## Шаг 3а. PHP 8.4 (Debian 13)

```bash
sudo apt install -y php8.4-fpm php8.4-cli php8.4-curl php8.4-intl php8.4-sqlite3 \
                    php8.4-mbstring php8.4-xml
```

## Шаг 3б. PHP 8.4 (Ubuntu 24.04 LTS)

```bash
sudo apt install -y software-properties-common
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update
sudo apt install -y php8.4-fpm php8.4-cli php8.4-curl php8.4-intl php8.4-sqlite3 \
                    php8.4-mbstring php8.4-xml
```

## Шаг 3в. Проверка расширений

Приложение не запустится без `curl`, `intl`, `json`, `pdo_sqlite`:

```bash
php -v
php -m | grep -E '^(curl|intl|json|pdo_sqlite|mbstring)$'
```

Должны вывестись все пять строк. Если `pdo_sqlite` отсутствует — не установлен
`php8.4-sqlite3`.

## Шаг 4. nginx, git, composer

```bash
sudo apt install -y nginx git unzip
php -r "copy('https://getcomposer.org/installer','composer-setup.php');"
sudo php composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm composer-setup.php
composer --version
```

## Шаг 5. Код приложения

```bash
sudo mkdir -p /var/www
sudo chown deploy:deploy /var/www
cd /var/www
git clone <адрес-репозитория> b24-docs-bot
cd b24-docs-bot
composer install --no-dev --optimize-autoloader
```

`--no-dev` не ставит PHPUnit и его зависимости: тесты на сервере не нужны.

## Шаг 6. Конфигурация

```bash
cp config.php.example config.php
nano config.php
```

Заполните `client_id` и `client_secret` — они появятся на шаге 9, так что пока
можно оставить заглушки и вернуться сюда. Остальное задайте сразу:

- `handler_url` — `https://bot.example.org/handler.php`;
- `bot_token` — любая случайная строка до 40 символов, например `openssl rand -hex 16`;
- `default_responsible_id` — ID сотрудника, на которого падут задачи, если у CRM-сущности
  нет ответственного;
- `oauth_server_url` — оставьте `https://oauth.bitrix24.tech/` для портала `.bitrix24.ru`;
  для `.bitrix24.com` замените на `https://oauth.bitrix.info/`;
- `scope` — **не трогайте**. Там намеренно и `task`, и `tasks`: первый для
  `task.checklistitem.*`, второй для `tasks.task.file.attach`. Уберёте один — бот
  упадёт на `insufficient_scope`.

Файл содержит `client_secret`, поэтому закройте его от посторонних:

```bash
chmod 640 config.php
```

## Шаг 7. Права на каталог var/

Сюда приложение пишет базу, логи и файл блокировки крона. Ключевой момент: **и
веб-процесс, и крон должны работать от одного пользователя**, иначе файлы SQLite
(`bot.sqlite`, `-wal`, `-shm`) окажутся с разными владельцами и одна из сторон
перестанет писать. Дальше везде используется `www-data`.

```bash
sudo mkdir -p var/data var/log
sudo chown -R deploy:www-data /var/www/b24-docs-bot
sudo chown -R www-data:www-data var
sudo chmod -R 775 var
sudo chown deploy:www-data config.php
```

Права на сам каталог `var/data`, а не только на файл базы, обязательны: SQLite
в режиме WAL создаёт рядом с базой служебные файлы.

## Шаг 8. nginx и HTTPS

Создайте `/etc/nginx/sites-available/b24-docs-bot`:

```nginx
server {
    listen 80;
    server_name bot.example.org;

    root /var/www/b24-docs-bot/public;

    location ~ \.php$ {
        try_files $uri =404;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    location ~ ^/(src|bin|var|vendor|config\.php)(/|$) {
        deny all;
        return 404;
    }

    location / {
        return 404;
    }
}
```

`try_files $uri =404` обязательна: без неё связка с `cgi.fix_pathinfo` позволяет
исполнить как PHP файл, который PHP-скриптом не является. `location /` с 404 закрывает
всё, кроме двух наших точек входа.

```bash
sudo ln -s /etc/nginx/sites-available/b24-docs-bot /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

Сертификат:

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d bot.example.org
```

Certbot сам добавит блок на 443 и редирект с 80. Проверьте:

```bash
curl -I https://bot.example.org/handler.php
```

Ожидается `HTTP/2 403` — это правильный ответ: запрос без `application_token`
отвергается. Если видите 200 или 502, разбирайтесь до перехода дальше.

## Шаг 9. Отключить вывод ошибок

```bash
sudo nano /etc/php/8.4/fpm/php.ini
```

```ini
display_errors = Off
log_errors = On
```

```bash
sudo systemctl restart php8.4-fpm
```

Обе точки входа сами перехватывают исключения, но при фатальной ошибке на раннем
этапе PHP допечатает трассировку в тело ответа — а это публичный эндпоинт.

## Шаг 10. Миграции

```bash
cd /var/www/b24-docs-bot
sudo -u www-data php bin/migrate.php
```

Ожидается `Применено миграций: 7`. Запускайте от `www-data`, иначе файл базы
создастся с владельцем `deploy` и веб-процесс не сможет в него писать.

## Шаг 11. Локальное приложение в Битрикс24

В портале: **Приложения → Разработчикам → Другое → Локальное приложение**.

- тип: **серверное**;
- URL приложения: `https://bot.example.org/install.php`;
- URL установки: `https://bot.example.org/install.php`;
- права (scope): `imbot`, `imopenlines`, `im`, `task`, `tasks`, `crm`, `disk`, `user`.

Сохраните — портал покажет `client_id` (код приложения) и `client_secret` (ключ).
Впишите их в `config.php` на сервере, затем нажмите в портале «Установить».

Откроется страница установщика. При успехе она сообщит идентификатор бота
и напомнит про шаг 12. Если регистрация бота не удалась — токены всё равно сохранены,
исправьте причину и выполните:

```bash
sudo -u www-data php bin/install_bot.php
```

## Шаг 12. Подключить бота к открытой линии

**Без этого шага бот не получит ни одного сообщения, при этом всё будет выглядеть
исправным.** Автоматизировать его нельзя.

В портале: **Контакт-центр → Открытые линии → нужная линия → редактировать →
раздел с чат-ботом → выбрать бота «Документы клиента»**, сохранить.

## Шаг 13. Крон

```bash
sudo crontab -u www-data -e
```

```cron
*/5 * * * * php /var/www/b24-docs-bot/bin/retry.php >> /var/www/b24-docs-bot/var/log/cron.log 2>&1
```

От `www-data` — по той же причине, что и миграции. Скрипт берёт `flock`, поэтому
пересечения запусков безопасны: второй экземпляр тихо выходит с кодом 0.

## Шаг 14. Проверка

```bash
cd /var/www/b24-docs-bot
sudo -u www-data php bin/selftest.php
```

Скрипт печатает пять проверок: конфигурация, токены портала, доступность бота,
режим чек-листа, состояние очереди. Любая остановка с кодом 1 — это то, что надо
чинить до боевой эксплуатации.

Затем живая проверка: напишите в открытую линию с клиентской стороны и приложите
документ. Ожидается, что в задаче клиента появится пункт чек-листа с этим файлом.
Если нет — смотрите `var/log/` и таблицу очереди:

```bash
sudo -u www-data sqlite3 var/data/bot.sqlite \
  "SELECT id, status, attempts, last_error FROM pending_files ORDER BY id DESC LIMIT 10;"
```

## Шаг 15. Резервное копирование

В `var/data/bot.sqlite` лежат OAuth-токены портала и кэш соответствия «клиент → задача».
Потеря файла означает переустановку приложения и то, что документы существующих клиентов
начнут попадать в новые задачи вместо старых. Ежедневная копия:

```bash
sudo crontab -u www-data -e
```

```cron
30 3 * * * sqlite3 /var/www/b24-docs-bot/var/data/bot.sqlite ".backup '/var/www/b24-docs-bot/var/data/backup-$(date +\%F).sqlite'"
```

Команда `.backup` корректно копирует базу под нагрузкой, в отличие от `cp`.
Складывайте копии за пределы сервера и удаляйте старые.

## Обновление приложения

```bash
cd /var/www/b24-docs-bot
git pull
composer install --no-dev --optimize-autoloader
sudo -u www-data php bin/migrate.php
sudo systemctl reload php8.4-fpm
```

Если изменился `handler_url`, после обновления перерегистрируйте бота:

```bash
sudo -u www-data php bin/install_bot.php
```

---

## Что проверить при первом боевом прогоне

Часть поведения Битрикс24 нельзя проверить без живого портала. Всё перечисленное
имеет рабочий запасной путь, но убедиться стоит:

1. **Имена документов в чек-листе.** Если вместо настоящих имён появляются `file-9077` —
   Диск не отдаёт `NAME`, и имя надо доставать из события.
2. **Режим чек-листа.** `bin/selftest.php` покажет, принял ли портал вложения прямо
   в пункт чек-листа или сработал запасной путь (файл прикреплён к задаче, в пункте ссылка).
3. **Поиск существующей задачи.** Создайте вручную открытую задачу с привязкой к тестовому
   контакту, очистите строку в `task_links` и пришлите документ: бот должен найти эту задачу,
   а не создать новую.
4. **Статусы задач.** Завершите задачу клиента и пришлите новый документ — должна создаться
   новая задача.
