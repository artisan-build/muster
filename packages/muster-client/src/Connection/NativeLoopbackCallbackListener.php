<?php

declare(strict_types=1);

namespace ArtisanBuild\MusterClient\Connection;

use RuntimeException;

final class NativeLoopbackCallbackListener implements LoopbackCallbackListener
{
    private const CALLBACK_PATH = '/muster/callback';

    /** @param resource $listener */
    private function __construct(private $listener, private readonly int $port) {}

    public static function bind(): self
    {
        $errorNumber = 0;
        $error = '';
        $listener = @stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $error);

        if (! is_resource($listener)) {
            throw new RuntimeException('Muster could not bind a loopback authorization listener.');
        }

        $address = stream_socket_get_name($listener, false);
        $port = is_string($address) ? parse_url('tcp://'.$address, PHP_URL_PORT) : false;

        if (! is_int($port) || $port < 1024 || $port > 65535) {
            fclose($listener);

            throw new RuntimeException('Muster did not receive an eligible loopback port.');
        }

        return new self($listener, $port);
    }

    public function redirectUri(): string
    {
        return 'http://127.0.0.1:'.$this->port.self::CALLBACK_PATH;
    }

    public function receive(string $expectedState, int $timeoutSeconds): string
    {
        if (! is_resource($this->listener)) {
            throw new RuntimeException('The Muster loopback listener is closed.');
        }

        stream_set_blocking($this->listener, false);
        $read = [$this->listener];
        $write = null;
        $except = null;

        if (@stream_select($read, $write, $except, $timeoutSeconds) !== 1) {
            throw new RuntimeException('Muster authorization timed out.');
        }

        $connection = @stream_socket_accept($this->listener, 2);

        if (! is_resource($connection)) {
            throw new RuntimeException('Muster could not accept the authorization callback.');
        }

        stream_set_timeout($connection, 2);
        $requestLine = fgets($connection, 4096);
        $target = is_string($requestLine) && preg_match('/\AGET ([^ ]+) HTTP\/1\.[01]\r?\n\z/D', $requestLine, $matches) === 1
            ? $matches[1]
            : null;

        try {
            $code = is_string($target) ? self::codeFromTarget($target, $expectedState) : null;
            $valid = is_string($code);
        } catch (RuntimeException) {
            $code = null;
            $valid = false;
        }

        $message = $valid
            ? 'Authorization received. You may close this window.'
            : 'Authorization refused.';
        $status = $valid ? '200 OK' : '400 Bad Request';
        $response = "HTTP/1.1 {$status}\r\nContent-Type: text/plain; charset=utf-8\r\nCache-Control: no-store\r\nContent-Length: ".strlen($message)."\r\nConnection: close\r\n\r\n{$message}";
        @fwrite($connection, $response);
        fclose($connection);

        if (! is_string($code)) {
            throw new RuntimeException('Muster refused the authorization callback.');
        }

        return $code;
    }

    public function close(): void
    {
        if (is_resource($this->listener)) {
            fclose($this->listener);
        }
    }

    public static function codeFromTarget(string $target, string $expectedState): string
    {
        if (preg_match('/%(?![A-Fa-f0-9]{2})/', $target) === 1) {
            throw new RuntimeException('The Muster callback was malformed.');
        }

        $parts = parse_url($target);

        if (! is_array($parts)
            || ($parts['path'] ?? null) !== self::CALLBACK_PATH
            || isset($parts['scheme'])
            || isset($parts['host'])
            || isset($parts['fragment'])
            || ! is_string($parts['query'] ?? null)) {
            throw new RuntimeException('The Muster callback path was invalid.');
        }

        $parameters = [];

        foreach (explode('&', $parts['query']) as $pair) {
            $fields = explode('=', $pair, 2);

            if (count($fields) !== 2) {
                throw new RuntimeException('The Muster callback query was malformed.');
            }

            $key = rawurldecode($fields[0]);
            $value = rawurldecode($fields[1]);

            if (! in_array($key, ['code', 'error', 'state'], true) || array_key_exists($key, $parameters)) {
                throw new RuntimeException('The Muster callback query was invalid.');
            }

            $parameters[$key] = $value;
        }

        if (! isset($parameters['state'])
            || ! hash_equals($expectedState, $parameters['state'])
            || isset($parameters['error'])
            || count($parameters) !== 2
            || ! isset($parameters['code'])
            || preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $parameters['code']) !== 1) {
            throw new RuntimeException('The Muster callback was refused.');
        }

        return $parameters['code'];
    }
}
