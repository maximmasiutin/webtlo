<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Clients;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\GuzzleException;
use KeepersTeam\Webtlo\Clients\Data\Torrent;
use KeepersTeam\Webtlo\Clients\Data\Torrents;
use KeepersTeam\Webtlo\Config\TorrentClientOptions;
use KeepersTeam\Webtlo\Helper;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Class Deluge
 * Supported by Deluge 2.1.1 [ plugins WebUi 0.2 and Label 0.3 ] and later.
 *
 * https://deluge.readthedocs.io/en/latest/devguide/how-to/curl-jsonrpc.html
 * https://github.com/kaysond/deluge-php/blob/master/deluge.class.php
 */
final class Deluge implements ClientInterface
{
    use Traits\AllowedFunctions;
    use Traits\AuthClient;
    use Traits\CheckDomain;
    use Traits\ClientTag;
    use Traits\RetryMiddleware;

    /** Счетчик запросов API. */
    private int $counter = 1;

    /** @var ?string[] */
    private ?array $labels = null;

    private Client    $client;
    private CookieJar $jar;

    public function __construct(
        private readonly LoggerInterface      $logger,
        private readonly TorrentClientOptions $options
    ) {
        $this->jar = new CookieJar();

        $this->client = new Client([
            'base_uri' => $this->getClientBase($this->options, 'json'),
            'cookies'  => $this->jar,
            'handler'  => $this->getDefaultHandler(),
            // Timeout options
            ...$this->options->getTimeoutOptions(),
        ]);

        if (!$this->login()) {
            throw new RuntimeException(
                'Не удалось авторизоваться в deluge api. Проверьте параметры доступа к клиенту.'
            );
        }
    }

    public function getTorrents(array $filter = []): Torrents
    {
        $fields = [
            (object) [],
            [
                'comment',
                'message',
                'name',
                'paused',
                'progress',
                'time_added',
                'total_size',
                'tracker_status',
                'save_path',
                'is_finished',
                'file_progress',
            ],
        ];

        $response = $this->makeRequest(method: 'core.get_torrents_status', params: $fields);

        $torrents = [];
        foreach ($response as $torrentHash => $torrent) {
            $torrentHash = strtoupper((string) $torrentHash);

            $torrentError = $torrent['message'] !== 'OK';
            preg_match('/.*Error: (.*)/', $torrent['tracker_status'], $matches);
            $trackerError = $matches[1] ?? '';

            $progress = $torrent['progress'] / 100;
            // Если торрент скачан полностью, проверив выбраны ли все файлы раздачи.
            if ($torrent['is_finished']) {
                $files    = $torrent['file_progress'];
                $progress = array_sum($files) / max(count($files), 1);

                unset($files);
            }

            $torrents[$torrentHash] = new Torrent(
                topicHash   : $torrentHash,
                clientHash  : $torrentHash,
                name        : (string) $torrent['name'],
                topicId     : $this->getTorrentTopicId($torrent['comment']),
                size        : (int) $torrent['total_size'],
                added       : Helper::makeDateTime((int) $torrent['time_added']),
                done        : $progress,
                paused      : (bool) $torrent['paused'],
                error       : $torrentError,
                trackerError: $trackerError,
                comment     : $torrent['comment'] ?: null,
                storagePath : $torrent['save_path'] ?? null
            );

            unset($torrentHash, $torrent, $torrentError, $trackerError, $progress);
        }

        return new Torrents($torrents);
    }

    public function addTorrent(string $torrentFilePath, string $savePath = '', string $label = ''): bool
    {
        $content = file_get_contents($torrentFilePath);
        if ($content === false) {
            $this->logger->error('Failed to upload file', ['filename' => basename($torrentFilePath)]);

            return false;
        }

        return $this->addTorrentContent($content, $savePath, $label);
    }

    public function addTorrentContent(string $content, string $savePath = '', string $label = ''): bool
    {
        $torrentOptions = !empty($savePath) ? ['download_location' => $savePath] : [];

        $fields = [
            'torrentName.torrent',
            base64_encode($content),
            $torrentOptions,
        ];

        return $this->sendRequest(method: 'core.add_torrent_file', params: $fields);
    }

    public function setLabel(array $torrentHashes, string $label = ''): bool
    {
        if (!empty($label)) {
            $label = str_replace(' ', '_', $label);
            if (!preg_match('|^[A-z0-9\-]+$|', $label)) {
                $this->logger->error('Found forbidden symbols in label', ['label_name' => $label]);

                return false;
            }
            $label = strtolower($label);

            if (!$this->checkLabelExists($label)) {
                return false;
            }
        }

        $result = true;
        foreach ($torrentHashes as $torrentHash) {
            $fields = [
                strtolower($torrentHash),
                $label,
            ];

            $response = $this->sendRequest(method: 'label.set_torrent', params: $fields);
            if ($response === false) {
                $result = false;
            }
        }

        return $result;
    }

    public function startTorrents(array $torrentHashes, bool $forceStart = false): bool
    {
        $params = $this->prepareHashes($torrentHashes);

        return $this->sendRequest('core.resume_torrent', $params);
    }

    public function stopTorrents(array $torrentHashes): bool
    {
        $params = $this->prepareHashes($torrentHashes);

        return $this->sendRequest('core.pause_torrent', $params);
    }

    public function removeTorrents(array $torrentHashes, bool $deleteFiles = false): bool
    {
        $result = true;
        foreach ($torrentHashes as $torrentHash) {
            $fields = [
                strtolower($torrentHash),
                $deleteFiles,
            ];

            $response = $this->sendRequest(method: 'core.remove_torrent', params: $fields);
            if ($response === false) {
                $result = false;
            }
        }

        return $result;
    }

    public function recheckTorrents(array $torrentHashes): bool
    {
        $params = $this->prepareHashes($torrentHashes);

        return $this->sendRequest(method: 'core.force_recheck', params: $params);
    }

    /**
     * Авторизация в торрент-клиенте.
     */
    private function login(): bool
    {
        if (!$this->authenticated) {
            try {
                // Авторизуемся в клиенте. Логин всегда deluge.
                $this->request('auth.login', [$this->options->credentials->password ?? '']);

                // Проверяем успешность и наличие куки авторизации.
                if ($this->jar->count() === 0) {
                    if ($this->options->credentials === null) {
                        $this->logger->warning('Не указан пароль для авторизации в торрент-клиенте.');
                    }
                    $this->logger->error('Failed to obtain session identifier');

                    return false;
                }

                // Пробуем подключится к клиенту.
                $this->authenticated = $this->sendRequest('web.connected');

                // Если подключение не удалось - начинаем шаманство.
                if (!$this->authenticated) {
                    $hosts = $this->makeRequest('web.get_hosts');

                    $webUiHost = $hosts[0][0] ?? null;
                    if ($webUiHost === null) {
                        $this->logger->error('Empty webUI host', $hosts);

                        return false;
                    }

                    $hostStatus = $this->makeRequest('web.get_host_status', [$webUiHost]);
                    if (in_array('Offline', $hostStatus)) {
                        $this->logger->error('WebUI host is offline', $hostStatus);
                    } elseif (in_array('Online', $hostStatus)) {
                        $this->authenticated = $this->sendRequest('web.connect', [$webUiHost]);
                    }
                }

                return $this->authenticated;
            } catch (Throwable $e) {
                $this->logger->error(
                    'Failed connect to client',
                    ['code' => $e->getCode(), 'message' => $e->getMessage()]
                );
            }

            return false;
        }

        return true;
    }

    /**
     * @param array<int, mixed> $params
     *
     * @throws GuzzleException
     */
    private function request(string $method, array $params = []): ResponseInterface
    {
        $options = [
            'method' => $method,
            'params' => $params,
            'id'     => $this->counter++,
        ];

        return $this->client->post('', ['json' => $options]);
    }

    /**
     * @param array<int, mixed> $params
     *
     * @return array<int|string, mixed>
     */
    private function makeRequest(string $method, array $params = []): array
    {
        try {
            $response = $this->request(method: $method, params: $params);
        } catch (GuzzleException $e) {
            $this->logger->error('Failed to make request', ['code' => $e->getCode(), 'message' => $e->getMessage()]);

            throw new RuntimeException('Failed to make request');
        }

        $array = json_decode($response->getBody()->getContents(), true);

        if (!empty($array['error']['message'])) {
            $this->logger->error('Failed to make request', (array) $array);

            throw new RuntimeException('Failed to make request');
        }

        return (array) $array['result'];
    }

    /**
     * @param array<int, mixed> $params
     */
    private function sendRequest(string $method, array $params = []): bool
    {
        try {
            $response = $this->request(method: $method, params: $params);

            return $response->getStatusCode() === 200;
        } catch (Throwable $e) {
            $this->logger->warning('Failed to send request', ['code' => $e->getCode(), 'message' => $e->getMessage()]);
        }

        return false;
    }

    /**
     * @param string[] $torrentHashes
     *
     * @return string[][]
     */
    private function prepareHashes(array $torrentHashes): array
    {
        return [array_map('strtolower', $torrentHashes)];
    }

    private function checkLabelExists(string $labelName): bool
    {
        if ($this->labels === null) {
            $enablePlugin = $this->enablePlugin('Label');
            if ($enablePlugin === false) {
                return false;
            }
            $this->labels = $this->makeRequest(method: 'label.get_labels');
        }

        if (in_array($labelName, array_map('strtolower', $this->labels))) {
            return true;
        }

        $this->labels[] = $labelName;

        return $this->sendRequest(method: 'label.add', params: [$labelName]);
    }

    /**
     * Включение плагина торрент клиента.
     */
    private function enablePlugin(string $pluginName): bool
    {
        return $this->sendRequest(method: 'core.enable_plugin', params: [$pluginName]);
    }
}
