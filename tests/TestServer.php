<?php
declare(strict_types=1);

/** Server bawaan PHP untuk pengujian + klien HTTP kecil (cookie jar, tanpa auto-redirect). */
final class TestServer
{
    /** @var resource */
    private $proc;
    public string $base;

    public static function start(string $dbPath, string $sessionDir): self
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
        fclose($sock);

        $root = dirname(__DIR__);
        $self = new self();
        $self->base = "http://127.0.0.1:$port";
        $env = array_merge(getenv(), ['KA_DB' => $dbPath, 'KA_SESSION_DIR' => $sessionDir]);
        $self->proc = proc_open(
            ['php', '-S', "127.0.0.1:$port", '-t', "$root/public", "$root/public/index.php"],
            [0 => ['pipe', 'r'], 1 => ['file', (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'), 'w'], 2 => ['file', (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'), 'w']],
            $pipes, $root, $env
        );
        for ($i = 0; $i < 60; $i++) {
            if (@fsockopen('127.0.0.1', $port)) {
                return $self;
            }
            usleep(100000);
        }
        throw new RuntimeException('Server uji tidak mau start');
    }

    public function stop(): void
    {
        if (is_resource($this->proc)) {
            proc_terminate($this->proc);
            proc_close($this->proc);
        }
    }
}

final class HttpClient
{
    private array $cookies = [];

    public function __construct(private string $base) {}

    public function request(string $method, string $path, array $data = []): array
    {
        $header = "Connection: close\r\n";
        if ($this->cookies) {
            $pairs = [];
            foreach ($this->cookies as $k => $v) {
                $pairs[] = "$k=$v";
            }
            $header .= 'Cookie: ' . implode('; ', $pairs) . "\r\n";
        }
        $http = ['method' => $method, 'header' => $header, 'ignore_errors' => true,
                 'follow_location' => 0, 'max_redirects' => 0, 'timeout' => 30];
        if ($method === 'POST') {
            $http['header'] .= "Content-Type: application/x-www-form-urlencoded\r\n";
            $http['content'] = http_build_query($data);
        }
        $body = @file_get_contents($this->base . $path, false, stream_context_create(['http' => $http]));
        $status = 0;
        $location = null;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+ (\d+)#', $h, $m)) {
                $status = (int) $m[1];
            } elseif (stripos($h, 'Location:') === 0) {
                $location = trim(substr($h, 9));
            } elseif (stripos($h, 'Set-Cookie:') === 0) {
                [$nv] = explode(';', trim(substr($h, 11)), 2);
                [$name, $val] = array_pad(explode('=', $nv, 2), 2, '');
                if ($val === '' || $val === 'deleted') {
                    unset($this->cookies[$name]);
                } else {
                    $this->cookies[$name] = $val;
                }
            }
        }
        return ['status' => $status, 'body' => $body === false ? '' : $body, 'location' => $location];
    }

    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    public function post(string $path, array $data): array
    {
        return $this->request('POST', $path, $data);
    }

    /** Ambil token CSRF dari halaman $path. */
    public function csrf(string $path): string
    {
        $html = $this->get($path)['body'];
        if (!preg_match('/name="csrf" value="([^"]+)"/', $html, $m)) {
            throw new RuntimeException("Token CSRF tidak ditemukan di $path");
        }
        return $m[1];
    }
}
