# API Reference

## ModuleProxy

This class is used to create proxy when accessing a module, it has the following
properties and methods:

- `string $name`: The name (with namespace, in dot `.` syntax) of the module.

This class is considered abstract, and shall not be used in user code.

## ModuleProxyApp

This class extends from `ModuleProxy`, and has the following extra properties
and methods:

- `__construct(string $name, $canServe = true)` Creates a root module proxy,
    `$name` will be used as a namespace for loading files, if `$canServe` is
    `false`, the proxy cannot be used to serve modules, and a client-only
    application will be created.
- `serve($options): RpcServer` Serves an RPC server according to the given URL
    or Unix socket filename, or provide a dict for detailed options.
    - `serve(string $url)`
    - `serve(array $options)`
- `connect($options): RpcClient` Connects to an RPC server according to the
    given URL or Unix socket filename, or provide a dict for detailed options.
    - `connect(string $url)`
    - `connect(array $options)`

A microse application must use this class to create a root proxy in order to
use its features.

#### Serve and Connect to IPC

If the first argument passed to `serve()` or `connect()` is a string of
filename, the RPC connection will be bound to a Unix socket, a.k.a. IPC, for
example:

```ts
server = $app.serve("/tmp/test.sock");
client = $app.connect("/tmp/test.sock");
```

**NOTE: `serve()` method is not available for client-only applications.**

## RpcChannel

This abstract class just indicates the RPC channel that allows modules to
communicate remotely. methods `ModuleProxyApp::serve()` and
`ModuleProxyApp::connect()` return its server and client implementations
accordingly.

The following properties and methods work in both implementations:

- `string $id` The unique ID of the server or the client.
- `getDSN(): string` Gets the data source name according to the configuration.
- `open(): void` Opens the channel. This method is called internally by
    `ModuleProxyApp.serve()` and `ModuleProxyApp.connect()`.
- `register($module): void` Registers a module to the channel.
- `onError(callable $handler): void` Binds an error handler invoked whenever an
    error occurred in asynchronous operations which can't be caught during
    run-time, the first arguments passed to the handler function is the
    exception raised.

Other than the above properties, the following keys listed in `ChannelOptions`
will be patched to the channel instance as properties as well.

### ChannelOptions

This array indicates the options used by the RpcChannel's initiation, all
the following keys are optional:

- `protocol => string` The valid values are `ws:`, `wss:` and `ws+unix:`.
- `hostname => string` Binds the connection to a hostname.
- `port => int` Binds the connection to a port.
- `pathname => string` If `protocol` is `ws:` or `wss:`, the pathname is used as
    the URL pathname for checking connection; if `protocol` is `ws+unix:`, the
    pathname sets the filename of the unix socket.
- `secret => string` Used as a password for authentication, if used, the client
    must provide it as well in order to grant permission to connect.
- `id => string` In the server implementation, sets the server id, in the client
    implementation, sets the client id.
- `codec => string` The codec used to encode and decode messages, currently the
    only supported codec is `JSON`.

## RpcServer

The server implementation of the RpcChannel, which has the following extra
methods:

- `publish(string $topic, mixed $data, $clients = []): bool`
    Publishes data to the corresponding topic, if `clients` (an array with
    client ids) are provided, the topic will only be published to them.
- `getClients(): array` Returns all IDs of clients that connected to the server.

### ServerOptions

This array indicates the options used by the RpcServer's initiation, it
inherits all [ChannelOptions](#ChannelOptions), along with the following keys:

- `certFile => string` If `protocol` is `wss:`, the server must set this
    option in order to ship a secure server.
- `keyFile => string` If `protocol` is `wss:`, the server must set this
    option in order to ship a secure server.
- `passphrase => string` If the ssl key file was encrypted with a passphrase,
    this option must be provided.

## RpcClient

The client implementation of the RpcChannel, which has the following extra
methods:

- `isConnecting(): bool` Whether the channel is in connecting state.
- `isConnected(): bool` Whether the channel is connected.
- `isClosed(): bool` Whether the channel is closed.
- `pause(): void` Pauses the channel and redirect traffic to other channels.
- `resume(): void` Resumes the channel and continue handling traffic.
- `subscribe(string $topic, callable $handle): RpcClient` Subscribes a handle
    function to the corresponding topic. The only argument passed to the
    `$handle` is the data sent to the topic.
- `unsubscribe(string $topic: str[, callable $handle]): bool` Unsubscribes the
    handle function or all handlers from the corresponding topic.

### ClientOptions

This array indicates the options used by the RpcClient's initiation, it
inherits all [ChannelOptions](#ChannelOptions), along with the following keys:

- `serverId => string` By default, the `serverId` is automatically set according
    to the DSN of the server, and updated after established the connect. However,
    if an ID is set when serving the RPC server, it would be better to set
    `serverId` to that ID as well.
- `timeout => int` Used to force a timeout error when an RPC request fires and
    doesn't get a response after a long time, default value is `5000`ms.
- `pingTimeout => int` Used to set the maximum delay of the connection, the
    client will constantly check the availability of the connection, default
    value is `5000`ms. If there are too much delay between the peers, the
    connection will be automatically released and a new connection will be
    created.
- `pintInterval => int` Used to set a internal timer for ping function to ensure
    the connection is alive, default value is `5000`ms. If the server doesn't
    response after sending a ping in time, the client will consider the server
    is down and will destroy and retry the connection.
