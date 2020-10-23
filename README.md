# Microse

Microse (stands for *Micro Remote Object Serving Engine*) is a light-weight
engine that provides applications the ability to serve modules as RPC services,
whether in another process or in another machine.

This is the PHP version of the microse implementation based on
[Swoole](https://www.swoole.com/). For API reference, please check the
[API documentation](./api.md), or the
[Protocol Reference](https://github.com/hyurl/microse/blob/master/docs/protocol.md).

## Install

```sh
composer install microse/microse-swoole
```

## Peel The Onion

In order to use microse, one must create a root `ModuleProxyApp` instance, so
other files can use it as a root namespace and access its sub-modules.

### Example

```php
// src/app.php
require __DIR__ . "../vendor/autoload.php";

use Microse\ModuleProxyApp;

$app = new ModuleProxyApp("App");
```

In other files, just define a class with the same name as the filename, so that
another file can access it directly via the `$app` namespace.

```php
// src/Bootstrap.php
namespace App;

class Bootstrap
{
    public function init(): void
    {
        // ...
    }
}
```

And other files can access to the modules via the namespace:

```php
// src/index.php
include_once "./app.php";

// Accessing the module as a singleton and calling its function directly.
$app->Bootstrap->init();
```

## Remote Service

The above example accesses the module and calls the function in the current
process, but we can do more, we can serve the module as a remote service, and
calls its functions as remote procedures.

### Example

For example, if I want to serve a user service in a different process, I just
have to do this:

```php
// src/Services/User.py
namespace App\Services;

class USer
{
    private $users = [
        ["firstName" => "David", "lastName" => "Wood"]
    ]

    public function getFullName(string $firstName): string
    {
        foreach ($this->users as $user) {
            if ($user["firstName"] === $firstName) {
                return $firstName . " " . $user["lastName"];
            }
        }
    }
}
```

```php
// src/server.php
include_once "./app.php";

Co\run(function () {
    global $app;
    $server = $app->serve("ws://localhost:4000");

    // Register the service, no need to include class file or set properties,
    // modules can be accessed directly.
    $server->register($app->Services->User);
});
```

Just try `php server.php` and the services will be started immediately.

And in the client-side code, connect to the service before using remote
functions.

```php
// client.php
include_once "./app.php";

Co\run(function () {
    global $app;
    $client = $app->connect("ws://localhost:4000");
    $client->register($app->Services->User);

    // Accessing the instance in local style but actually calling remote.
    // Since we're using swoole, this procedure is actually asynchronous.
    $fullName = $app->Services->User->getFullName("David");

    echo $fullName . "\n"; // David Wood
});
```

NOTE: to ship a service in multiple server nodes, just create and connect to
multiple channels, and register the service to each of them, when calling remote
functions, microse will automatically calculate routes and redirect traffics to
them.

NOTE: RPC calling will serialize (via JSON) all input and output data, those
data that cannot be serialized will be lost during transmission.

## Generator Support

When in the need of transferring large data, generator functions could be a
great help, unlike general functions, which may block network traffic when
transmitting large data since they send the data as a whole, generator functions,
on the other hand, will transfer the data piece by piece.

```php
// src/Services/User.php
namespace App\Services;

class User
{
    private $friends = [
        "David" => [
            [ "firstName" => "Albert", "lastName" => "Einstein" ],
            [ "firstName" => "Nicola", "lastName" => "Tesla" ],
            // ...
        ],
        // ...
    ];

    public function getFriendsOf(string $name): \Generator
    {
        $friends = @$this->friends[$name];

        if ($friends) {
            foreach ($friends as $friend) {
                yield $friend["firstName"] . " " . $friend["lastName"];
            }

            return "These are all friends";
        }
    }
}
```

```php
$generator = $app->Services->User->getFriendsOf("David");

foreach ($generator as $name) {
    echo $name . "\n";
    // Albert Einstein
    // Nicola tesla
    // ...
}

// We can get the return value as well:
$returns = $generator->getReturn(); // These are all friends
```

The generator function returns an rpc version of the Generator, so if you want
to send data into the generator, you can use `Generator::send()` to do so, the
value passed to the method will be delivered to the remote instance as well.

## Life Cycle Support

Since swoole already handles asynchronous operations under the hood, so
lifecycle support is done by the original `__construct` and `__destruct` methods,
and there is no need for any extra efforts.

## Standalone Client

Microse also provides a way to be running as a client-only application, in this
case the client will not actually load any modules since there are no such files,
instead, it just map the module names so you can use them as usual.

In the following example, we assume that `$app->services->user` service is
served by a Node.js program, and we can use it in our PHP program as usual.

```php
use Microse\ModuleProxyApp;

$app = new ModuleProxyApp("app", false); // pass the second argument false

Co\run(function () use ($app) {
    $client = $app->connect("ws://localhost:4000");
    $client->register($app->services->user);

    $fullName = $app->services->user->getFullName("David");

    echo $fullName . "\n"; // David Wood
});
```
