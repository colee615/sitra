# NAME BASE

BASE

## LANGUAGES, FRAMEWORKS USED AND TOOLS 🛠️

* PHP 8.2
* LARAVEL 12
* LIVEWIRE
* COMPOSER
* MYSQL

  https://laravel-excel.com/                                    				maatwebsite/excel
  https://github.com/barryvdh/laravel-dompdf                    		barryvdh/laravel-dompdf
  https://github.com/jeroennoten/Laravel-AdminLTE               		jeroennoten/laravel-adminlte
  https://spatie.be/docs/laravel-permission/v5/introduction    		spatie/laravel-permission
  https://laravel-livewire.com/                             				livewire/livewire
  https://github.com/milon/barcode                               			milon/barcode
  https://jwt-auth.readthedocs.io/en/develop/					tymon/jwt-auth
  https://laravel.com/docs/11.x/pulse							laravel/pulse
  https://log-viewer.opcodes.io/								opcodesio/log-viewer
  https://github.com/reytechcode/Toastr-Laravel				yoeunes/toast

## Installation

We clone the repository

```bash
git clone https://github.com/elmloko/trackpak
```

We install our dependencies with [XAMMP](https://www.apachefriends.org/es/download.html)

DataBases

* We import the database to MySQL

```bash
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=trackpak
DB_USERNAME=root
DB_PASSWORD=
```

* migrate and seed in database

## Credentials

| User  | Pass  |
| ----- | ----- |
| Admin | admin |

## Authors and acknowledgment

Developers of this software

* Marco Antonio Espinoza Rojas

## System installation

* Install Node dependencies:

```bash
npm install
```

* Install Composer dependencies:

```bash
composer install
```

* Copy the environment configuration file:

```bash
cp .env.example .env
```

* Generate the application key:

```bash
php artisan key:generate
```

## System configuration

* System cleanup and optimization:

```bash
php artisan optimize
```

* Generate jwt token:

```bash
php artisan jwt:secret
```

* Capturing Entries:

```bash
php artisan pulse:check
```

## License

[GNU](https://www.gnu.org/licenses/gpl-3.0.en.html) `<p align="center"><a href="https://laravel.com" target="_blank">``<img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a>``</p>`
