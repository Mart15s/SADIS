# SADiS Backend

Laravel REST API naudoja PostgreSQL, Eloquent ir Laravel Sanctum.

## Lokalūs veiksmai

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

## Testai

```powershell
php artisan config:clear
php artisan route:clear
php artisan cache:clear
php artisan test
php artisan route:list
```

Demo duomenys seedinami tik tiesioginiu seeder kvietimu arba su aiškiu env flagu:

```powershell
php artisan db:seed --class=CurrentVersionDemoSeeder
```

Render paleidimo kelias aprašytas repo šaknies `DEPLOY_RENDER.md`.
