# qrstuff/scout-mongodb

[MongoDB](https://www.mongodb.com/) (not MongoDB Atlas) engine for the [Laravel Scout](https://laravel.com/docs/scout) search.

[![Latest Version][latest-version-image]][latest-version-url]
[![Downloads][downloads-image]][downloads-url]
[![PHP Version][php-version-image]][php-version-url]
[![License][license-image]](LICENSE)

### Installation

```bash
composer require qrstuff/scout-mongodb
```

### Usage

Before following this guide, make sure you have installed and set up [laravel/scout](https://laravel.com/docs/scout) in your project already.

In your `config/database.php`, add the mongodb connection:

```php
return [

    // other stuff

    'connections' => [

        // other stuff

        'mongodb' => [
            'driver' => 'mongodb',
            'dsn' => env('MONGODB_URL'),
            'database' => env('MONGODB_DATABASE', 'example'),
        ],

    ],

    // other stuff

];
```

In your `config/scout.php`, add the mongodb definition:

```php
return [

    // other stuff
    'mongodb' => [
        'connection' => env('SCOUT_MONGODB_CONNECTION', 'mongodb'),
        'index-settings' => [
            // App\Models\User::class => [
            //     'searchableAttributes'=> ['name', 'email', 'phone'],
            //     'filterableAttributes'=> [['country' => 1]], // 1 = ASC, 2 = DESC
            // ],
        ],
    ],

    // other stuff

];
```

Then add the `Searchable` trait your model classes as follows:

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

class Customer extends Model
{
    use Searchable; // include the trait
}
```

You can now search across your models as below:

```php
use App\Models\Customer;

$customersInIndia = Customer::search('vpz')->where('country', 'IN')->get();
```

### License

See [LICENSE](LICENSE) file.

[latest-version-image]: https://img.shields.io/github/release/qrstuff/scout-mongodb.svg?style=flat-square
[latest-version-url]: https://github.com/qrstuff/scout-mongodb/releases
[downloads-image]: https://img.shields.io/packagist/dt/qrstuff/scout-mongodb.svg?style=flat-square
[downloads-url]: https://packagist.org/packages/qrstuff/scout-mongodb
[php-version-image]: http://img.shields.io/badge/php-7.4+-8892be.svg?style=flat-square
[php-version-url]: https://www.php.net/downloads
[license-image]: https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square
