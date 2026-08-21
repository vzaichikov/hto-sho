# Хто Шо?

Початковий каркас україномовного вебпроєкту.

## Технології

- Laravel 13
- MySQL
- Tailwind CSS 4
- Vite 8
- Laravel Boost

## Локальний запуск

```bash
composer install
npm ci
php artisan migrate
npm run build
```

Локальна адреса: [https://hto-sho.local](https://hto-sho.local).

## Розгортання

Захищений сценарій розгортання зберігається у `.agents/skills/hto-sho-production-deploy`.

```bash
.agents/skills/hto-sho-production-deploy/scripts/deploy-production.sh --dry-run
.agents/skills/hto-sho-production-deploy/scripts/deploy-production.sh --execute
```

Робоча адреса: [https://hto-sho.hobotix.dev](https://hto-sho.hobotix.dev).
