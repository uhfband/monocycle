# monocycle

`monocycle` is a lightweight event loop for PHP. It can manage sockets, child processes, and timers in a single loop.

This README covers **Timers**, **Processes**, **Sockets** and **Files**.

## Installation

```bash
composer require uhf/monocycle
```

## Basic event loop

Create timers/processes, then start the loop:

```php
<?php

use Uhf\Monocycle\Monocycle;

Monocycle::run();
```

Stop it from any callback with:

```php
Monocycle::stop();
```

`Monocycle::stop($graceful, $sendSignal)` drops all timers and closes every socket (listening servers and connections); with `$sendSignal = true` it sends `SIGINT` to every child process, and with `$graceful = true` the loop keeps running until all child processes have exited instead of halting at once.

You can also tune loop frequency:

```php
Monocycle::setFreq(50); // iterations per second, 0 = run without sleep throttling
```

and the level of the built-in stdout logger (default `Logger::WARNING`), or replace the logger altogether:

```php
use Monolog\Logger;

Monocycle::setLogLevel(Logger::INFO);
Monocycle::setLogger($myPsrLogger); // keeps its own levels
```

## Timers

`Uhf\Monocycle\Timer\Timer` covers all timer kinds. Construct it with a callback (it receives the timer), pick a schedule with one of the chainable setters, then call `->start()`. `->stop()` removes it from the loop.

| Setter | Schedule |
| --- | --- |
| `setDelay(int $seconds)` | once, `$seconds` after `start()` |
| `setInterval(int $seconds)` | repeatedly, every `$seconds` |
| `setTimestamp(int $ts)` / `setDateTime(DateTimeInterface $dt)` | once, at that time |
| `setCronExpression(string $expr)` | on a cron schedule |

### One-shot timer

```php
<?php

use Uhf\Monocycle\Monocycle;
use Uhf\Monocycle\Timer\Timer;

(new Timer(static function () {
    echo "Runs once after ~5 seconds\n";
}))->setDelay(5)->start();

Monocycle::run();
```

### Interval timer

```php
<?php

use Uhf\Monocycle\Monocycle;
use Uhf\Monocycle\Timer\Timer;

$count = 0;

(new Timer(static function (Timer $timer) use (&$count) {
    $count++;
    echo "Tick {$count}\n";

    if ($count >= 3) {
        $timer->stop();
        Monocycle::stop();
    }
}))->setInterval(1)->start();

Monocycle::run();
```

### Cron timer

```php
<?php

use Uhf\Monocycle\Monocycle;
use Uhf\Monocycle\Timer\Timer;

(new Timer(static function () {
    echo "Runs every 5 minutes\n";
}))->setCronExpression('*/5 * * * *')->start();

Monocycle::run();
```

### Timer notes

- Interval and cron timers reschedule themselves after each run; the others stop after firing once.
- `start()` throws `Uhf\Monocycle\Timer\TimerException` if the timer is already running or has no schedule.
- `reset()` pushes a delay/interval timer's next run back to `time() + seconds`.
- Throwing `TimerException` from a callback logs the error and removes the timer; any other exception propagates out of `Monocycle::run()`.
- Timer processing is second-based (`time()`), so scheduling precision is at second granularity.

## Processes

Use `Uhf\Monocycle\Process\Process` to run child processes with non-blocking pipes.

### Minimal process example

```php
<?php

use Uhf\Monocycle\Monocycle;
use Uhf\Monocycle\Process\Process;

$process = new class(['php', '-r', 'echo "hello from child\\n";']) extends Process {
    protected function onStdout(string $data): void
    {
        echo "child says: {$data}";
    }

    protected function onExit(int $code): void
    {
        echo "child exit code: {$code}\n";
        Monocycle::stop();
    }
};

$process->start();
Monocycle::run();
```

### Writing to child stdin

By default, process descriptor `0` is stdin (`pipe`, `r`) and descriptor `1` is stdout (`pipe`, `w`). Instead of subclassing, you can also set the `onStdout` / `onStderr` / `onData` / `onExit` closures; each gets the process first:

```php
<?php

use Uhf\Monocycle\Monocycle;
use Uhf\Monocycle\Process\Process;

$process = new Process(['php', '-r', 'while (($line = fgets(STDIN)) !== false) { echo strtoupper($line); }']);
$process->onStdout = static function (Process $process, string $data) {
    echo "reply: {$data}";
};
$process->onExit = static function (Process $process, int $code) {
    echo "done ({$code})\n";
    Monocycle::stop();
};

$process->start();
$process->write("hello\n", Process::STDIN);
$process->closePipe(Process::STDIN); // EOF on the child's stdin lets it finish

Monocycle::run();
```

### Process notes

- Call `->start()` to register the process in the loop.
- Override `onStdout(string $data)`, `onStderr(string $data)` and `onData(string $data, int $pipeNum)` (any descriptor other than 0-2), or set the closures of the same name, which are called as `($process, $data)` and `($process, $data, $pipeNum)`.
- Override `onExit(int $code)` or set `$onExit` (called as `($process, $code)`) for exit handling/cleanup. A child killed by a signal reports `128 + signal`.
- `write()` is buffered and flushed when the pipe is writable, not written synchronously.
- `closePipe()` closes the pipe once data already passed to `write()` has been flushed, so it is safe to call right after writing.
- Use `->terminate()`, `->interrupt()`, or `->kill()` to stop a child process.
- Child process status is checked by the loop periodically (about once per second), and immediately when one of its pipes closes (the child closing its end, or a pipe error).

## Sockets

The `Uhf\Monocycle\Socket` classes cover TCP and unix domain stream sockets (`Server`, `Connection`) and UDP (`UdpSocket`). Everything is non-blocking: you set callback closures, and the loop calls them.

### TCP echo server and client

```php
<?php

use Uhf\Monocycle\Monocycle;
use Uhf\Monocycle\Socket\Connection;
use Uhf\Monocycle\Socket\Server;

$server = new Server('tcp://127.0.0.1:9000');
$server->onConnection = static function (Server $server, Connection $client) {
    echo "accepted {$client->getRemoteAddress()}\n";
    $client->onData = static function (Connection $client, string $data) {
        $client->write($data); // echo it back
    };
};

$client = new Connection();
$client->onConnect = static function (Connection $conn) {
    $conn->write("hello\n");
};
$client->onData = static function (Connection $conn, string $data) {
    echo "echoed: {$data}";
    $conn->close();
    Monocycle::stop();
};
$client->onConnectionError = static function (Connection $conn, string $message) {
    echo "connect failed: {$message}\n";
    Monocycle::stop();
};
$client->connect('tcp://127.0.0.1:9000');

Monocycle::run();
```

Accepted connections are plain `Connection` objects. To use your own class, pass it as the second argument, `new Server($address, MyConnection::class)`. Set its callbacks in `onConnection`, which runs before the connection's `onConnect`.

### Unix domain sockets

Use a `unix://` address on both sides. A socket file left behind by a previous run is removed by the `Server` constructor:

```php
$server = new Server('unix:///tmp/app.sock');

$client = new Connection();
$client->connect('unix:///tmp/app.sock');
```

### TLS client

Pass a crypto method, and optionally [SSL context options](https://www.php.net/manual/en/context.ssl.php), to `connect()`. The handshake runs inside the loop, and `onConnect` fires once it completes:

```php
$client->connect(
    'tcp://example.com:443',
    STREAM_CRYPTO_METHOD_TLS_CLIENT,
    ['peer_name' => 'example.com', 'verify_peer' => true]
);
```

TLS is client side only; `Server` does not terminate TLS.

### UDP

`UdpSocket` binds in its constructor (default `udp://0.0.0.0:0`, an ephemeral port), so it is in the loop immediately:

```php
<?php

use Uhf\Monocycle\Monocycle;
use Uhf\Monocycle\Socket\UdpSocket;

$server = new UdpSocket('udp://127.0.0.1:9001');
$server->onRecvFrom = static function (UdpSocket $socket, string $data, string $from) {
    $socket->sendTo(strtoupper($data), $from);
};

$client = new UdpSocket('udp://127.0.0.1:0');
$client->onRecvFrom = static function (UdpSocket $socket, string $data, string $from) {
    echo "reply from {$from}: {$data}\n";
    Monocycle::stop();
};
$client->sendTo('ping', '127.0.0.1:9001');

Monocycle::run();
```

### Socket notes

- `Connection` callbacks get the connection first: `onConnect($conn)`, `onData($conn, $data)`, `onDrain($conn)`, `onDisconnect($conn)`, `onConnectionError($conn, $message)`. `Server::$onConnection` gets the server and the new connection: `onConnection($server, $conn)`.
- `onDisconnect` fires when the peer closes the connection or a read/write fails. `onConnectionError` fires when `connect()` fails (refused, unresolvable, connect timeout, TLS handshake failure). Both close the connection afterwards at once, dropping any data still waiting to be sent, even if you had called `close()` to wait for it.
- `write()` is buffered. Data written before the connection is established is sent once it is. `onDrain` fires when the buffer has been flushed. A write that would take the buffer to 1MiB (1,048,576 bytes) or more throws `StreamException` (`setWriteBufferSize()` changes the limit), so send large payloads in pieces from `onDrain`.
- `close()` waits for buffered data to be sent first; `close(false)` closes at once. A connection you close yourself gets no `onDisconnect`.
- `setConnectTimeout($s)`, `setReadTimeout($s)` and `setWriteTimeout($s)` (chainable, in seconds, none set by default) disconnect with `Connect timeout`, `Read timeout` or `Write timeout`. Set them before `connect()`. A connect timeout ends in `onConnectionError`, the others in `onDisconnect`. Only incoming data resets the read timeout, so a connection that only sends still times out. The write timeout runs while buffered data is waiting to be sent. Timeouts are checked once per second, so they can fire up to about a second late or early.
- Instead of closures, you can subclass `Connection` and override the protected `onConnect()`, `onData()`, etc. methods. An `onConnect()` override must call `parent::onConnect()`, which starts reading.
- `UdpSocket::sendTo()` sends immediately (no buffering); a receive error logs a warning and closes the socket.

## Files

`Uhf\Monocycle\File\File` wraps a file in the same buffered, callback-driven interface as sockets and pipes. The constructor takes `fopen()` arguments and throws `Uhf\Monocycle\Stream\StreamException` if the file cannot be opened.

### Reading a file

`readAsync($length)` delivers up to `$length` bytes through `onData`, in chunks of at most 128KiB (`setReadChunkLength()` changes that). `onEnd` fires if the end of the file comes first:

```php
<?php

use Uhf\Monocycle\File\File;
use Uhf\Monocycle\Monocycle;

$file = new File(__FILE__, 'r');
$size = 0;
$file->onData = static function (File $file, string $data) use (&$size) {
    $size += strlen($data);
};
$file->onEnd = static function (File $file) use (&$size) {
    echo "read {$size} bytes\n";
    $file->close();
    Monocycle::stop();
};
$file->readAsync(PHP_INT_MAX);

Monocycle::run();
```

### Writing a file

`write()` is buffered and flushed by the loop; `onDrain` fires once everything has been written:

```php
<?php

use Uhf\Monocycle\File\File;
use Uhf\Monocycle\Monocycle;

$file = new File(sys_get_temp_dir() . '/monocycle.log', 'a');
$file->onDrain = static function (File $file) {
    $file->close();
    Monocycle::stop();
};
$file->write("first line\n");
$file->write("second line\n");

Monocycle::run();
```

### File notes

- `close()` closes the file at once and drops data that has not been written yet, so close a file you are writing from `onDrain`.
- `write()` throws `StreamException` if the buffered data would reach the write buffer limit (1MiB by default, `setWriteBufferSize()` changes it). The same limit applies to sockets and process pipes.
- When a `readAsync()` request has been fully read, the file stops being read until the next `readAsync()`. At the end of the file it fires `onEnd` and stays open, so you can call `readAsync()` again later, for example after the file grows.
- `seek($offset, $whence)`, `rewind()`, `stat()` (as `fstat()`) and the synchronous `readSync($length)` work as their PHP counterparts.
- `lock($operation = LOCK_EX)` is a non-blocking `flock()`: it returns `false` instead of waiting when another handle holds a conflicting lock. Release the lock with `lock(LOCK_UN)`.
- Regular files are always reported ready by `stream_select()`, so file I/O goes through the loop but each read or write can still block on the disk for its duration.

## Running tests

```bash
./vendor/bin/phpunit
```
