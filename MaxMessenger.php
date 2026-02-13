<?php

/**
 * MAX Messenger API PHP Library
 * Official implementation based on https://dev.max.ru/docs-api
 * 
 * @version 2.0.0
 * @author MAX API Team
 * @license MIT
 */

namespace MaxMessenger;

// -------------------------------------------------------------------------
//  Исключения
// -------------------------------------------------------------------------

class ApiException extends \Exception
{
    protected ?array $context = null;

    public function __construct(string $message = "", int $code = 0, ?\Throwable $previous = null, ?array $context = null)
    {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    public function getContext(): ?array
    {
        return $this->context;
    }
}

class ValidationException extends \InvalidArgumentException {}

// -------------------------------------------------------------------------
//  Конфигурация
// -------------------------------------------------------------------------

class Config
{
    private string $token;
    private string $baseUrl;
    private int $timeout;
    private ?string $proxy;
    private bool $verifySSL;

    public function __construct(
        string $token,
        string $baseUrl = 'https://platform-api.max.ru',
        int $timeout = 30,
        ?string $proxy = null,
        bool $verifySSL = true
    ) {
        $this->token = $token;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = $timeout;
        $this->proxy = $proxy;
        $this->verifySSL = $verifySSL;
    }

    public function getToken(): string { return $this->token; }
    public function getBaseUrl(): string { return $this->baseUrl; }
    public function getTimeout(): int { return $this->timeout; }
    public function getProxy(): ?string { return $this->proxy; }
    public function isVerifySSL(): bool { return $this->verifySSL; }
}

// -------------------------------------------------------------------------
//  HTTP Client Interface
// -------------------------------------------------------------------------

interface HttpClientInterface
{
    /**
     * @throws ApiException
     */
    public function request(string $method, string $uri, array $options = []): array;
}

// -------------------------------------------------------------------------
//  cURL HTTP Client
// -------------------------------------------------------------------------

class CurlHttpClient implements HttpClientInterface
{
    private Config $config;

    public function __construct(Config $config)
    {
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('cURL extension is required');
        }
        $this->config = $config;
    }

    public function request(string $method, string $uri, array $options = []): array
    {
        $url = $this->config->getBaseUrl() . '/' . ltrim($uri, '/');

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->config->getTimeout());
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->config->isVerifySSL());
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->config->isVerifySSL() ? 2 : 0);

        if ($this->config->getProxy()) {
            curl_setopt($ch, CURLOPT_PROXY, $this->config->getProxy());
        }

        // Важно: токен без "Bearer", чистое значение
        $headers = [
            'Authorization: ' . $this->config->getToken(),
            'Accept: application/json',
        ];

        if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $headers[] = 'Content-Type: application/json';
            if (isset($options['json'])) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($options['json'], JSON_UNESCAPED_UNICODE));
            }
        }

        if ($method === 'GET' && !empty($options['query'])) {
            $url .= '?' . http_build_query($options['query']);
            curl_setopt($ch, CURLOPT_URL, $url);
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new ApiException('cURL error: ' . $error);
        }

        // MAX API возвращает чистый JSON, без обёртки {"ok": ...}
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ApiException('Invalid JSON response: ' . json_last_error_msg());
        }

        // Обработка HTTP-кодов ошибок согласно документации
        if ($httpCode >= 400) {
            $errorMessage = $data['error'] ?? $data['message'] ?? 'Unknown API error';
            throw new ApiException($errorMessage, $httpCode, null, $data);
        }

        return $data;
    }
}

// -------------------------------------------------------------------------
//  DTO: User (Бот / Пользователь)
// -------------------------------------------------------------------------

class User
{
    public int $user_id;
    public string $name;
    public string $username;
    public bool $is_bot;
    public int $last_activity_time;

    public static function fromArray(array $data): self
    {
        $user = new self();
        $user->user_id = $data['user_id'];
        $user->name = $data['name'];
        $user->username = $data['username'];
        $user->is_bot = $data['is_bot'] ?? false;
        $user->last_activity_time = $data['last_activity_time'];
        return $user;
    }
}

// -------------------------------------------------------------------------
//  DTO: Chat
// -------------------------------------------------------------------------

class Chat
{
    public int $id;
    public string $type;
    public ?string $title;
    public ?string $username;
    public ?string $first_name;
    public ?string $last_name;

    public static function fromArray(array $data): self
    {
        $chat = new self();
        $chat->id = $data['id'];
        $chat->type = $data['type'];
        $chat->title = $data['title'] ?? null;
        $chat->username = $data['username'] ?? null;
        $chat->first_name = $data['first_name'] ?? null;
        $chat->last_name = $data['last_name'] ?? null;
        return $chat;
    }
}

// -------------------------------------------------------------------------
//  DTO: Message
// -------------------------------------------------------------------------

class Message
{
    public int $id;
    public Chat $chat;
    public ?User $from;
    public int $date;
    public string $text;
    public ?string $format;
    public ?array $attachments;
    public ?Message $reply_to_message;

    public static function fromArray(array $data): self
    {
        $message = new self();
        $message->id = $data['id'];
        $message->chat = Chat::fromArray($data['chat']);
        $message->from = isset($data['from']) ? User::fromArray($data['from']) : null;
        $message->date = $data['date'];
        $message->text = $data['text'] ?? '';
        $message->format = $data['format'] ?? null;
        $message->attachments = $data['attachments'] ?? null;
        $message->reply_to_message = isset($data['reply_to_message']) 
            ? self::fromArray($data['reply_to_message']) 
            : null;
        return $message;
    }
}

// -------------------------------------------------------------------------
//  DTO: Update
// -------------------------------------------------------------------------

class Update
{
    public int $update_id;
    public ?Message $message;
    public ?Message $edited_message;
    public ?Message $channel_post;
    public ?Message $edited_channel_post;
    public ?array $callback_query; // message_callback

    public static function fromArray(array $data): self
    {
        $update = new self();
        $update->update_id = $data['update_id'];
        $update->message = isset($data['message']) ? Message::fromArray($data['message']) : null;
        $update->edited_message = isset($data['edited_message']) ? Message::fromArray($data['edited_message']) : null;
        $update->channel_post = isset($data['channel_post']) ? Message::fromArray($data['channel_post']) : null;
        $update->edited_channel_post = isset($data['edited_channel_post']) ? Message::fromArray($data['edited_channel_post']) : null;
        $update->callback_query = $data['callback_query'] ?? null;
        return $update;
    }
}

// -------------------------------------------------------------------------
//  Клавиатура: построитель инлайн-кнопок
// -------------------------------------------------------------------------

class InlineKeyboard
{
    private array $buttons = [];

    /**
     * Добавить ряд кнопок
     */
    public function row(array $row): self
    {
        $this->buttons[] = $row;
        return $this;
    }

    /**
     * Добавить кнопку в текущий ряд
     */
    public function button(string $text, string $type = 'callback', ?string $payload = null, ?string $url = null): self
    {
        if (empty($this->buttons)) {
            $this->buttons[] = [];
        }

        $btn = [
            'type' => $type,
            'text' => $text,
        ];

        if ($payload !== null) {
            $btn['payload'] = $payload;
        }

        if ($url !== null) {
            $btn['url'] = $url;
        }

        $this->buttons[array_key_last($this->buttons)][] = $btn;
        return $this;
    }

    /**
     * Получить структуру для attachment
     */
    public function toAttachment(): array
    {
        return [
            'type' => 'inline_keyboard',
            'payload' => [
                'buttons' => $this->buttons
            ]
        ];
    }
}

// -------------------------------------------------------------------------
//  API: Me (Информация о боте)
// -------------------------------------------------------------------------

class Me
{
    private Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * GET /me — информация о текущем боте
     */
    public function get(): User
    {
        $response = $this->client->getHttpClient()->request('GET', '/me');
        return User::fromArray($response);
    }
}

// -------------------------------------------------------------------------
//  API: Messages
// -------------------------------------------------------------------------

class Messages
{
    private Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * POST /messages — отправить сообщение
     * 
     * @param int|string $chatId
     * @param string $text
     * @param array $options Допустимые ключи:
     *   - format: 'markdown'|'html'
     *   - attachments: array
     *   - reply_to_message_id: int
     *   - disable_notification: bool
     */
    public function send($chatId, string $text, array $options = []): Message
    {
        if (empty($text)) {
            throw new ValidationException('Message text cannot be empty');
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
        ];

        if (isset($options['format'])) {
            $payload['format'] = $options['format'];
        }

        if (isset($options['attachments'])) {
            $payload['attachments'] = $options['attachments'];
        }

        if (isset($options['reply_to_message_id'])) {
            $payload['reply_to_message_id'] = $options['reply_to_message_id'];
        }

        if (isset($options['disable_notification'])) {
            $payload['disable_notification'] = $options['disable_notification'];
        }

        $response = $this->client->getHttpClient()->request('POST', '/messages', [
            'json' => $payload
        ]);

        return Message::fromArray($response);
    }

    /**
     * GET /messages/{messageId} — получить сообщение
     */
    public function get(int $messageId, $chatId): Message
    {
        $response = $this->client->getHttpClient()->request('GET', "/messages/{$messageId}", [
            'query' => ['chat_id' => $chatId]
        ]);
        return Message::fromArray($response);
    }

    /**
     * PATCH /messages/{messageId} — редактировать сообщение
     */
    public function edit(int $messageId, $chatId, string $newText, array $options = []): Message
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $newText,
        ];

        if (isset($options['format'])) {
            $payload['format'] = $options['format'];
        }

        if (isset($options['attachments'])) {
            $payload['attachments'] = $options['attachments'];
        }

        $response = $this->client->getHttpClient()->request('PATCH', "/messages/{$messageId}", [
            'json' => $payload
        ]);

        return Message::fromArray($response);
    }

    /**
     * DELETE /messages/{messageId} — удалить сообщение
     */
    public function delete(int $messageId, $chatId): bool
    {
        $this->client->getHttpClient()->request('DELETE', "/messages/{$messageId}", [
            'json' => ['chat_id' => $chatId]
        ]);
        return true;
    }
}

// -------------------------------------------------------------------------
//  API: Chats
// -------------------------------------------------------------------------

class Chats
{
    private Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * GET /chats — список чатов
     */
    public function list(array $params = []): array
    {
        $response = $this->client->getHttpClient()->request('GET', '/chats', [
            'query' => $params
        ]);

        $chats = [];
        foreach ($response as $item) {
            $chats[] = Chat::fromArray($item);
        }
        return $chats;
    }

    /**
     * GET /chats/{chatId} — информация о чате
     */
    public function get($chatId): Chat
    {
        $response = $this->client->getHttpClient()->request('GET', "/chats/{$chatId}");
        return Chat::fromArray($response);
    }

    /**
     * PATCH /chats/{chatId} — изменить информацию о чате
     */
    public function update($chatId, array $data): Chat
    {
        $response = $this->client->getHttpClient()->request('PATCH', "/chats/{$chatId}", [
            'json' => $data
        ]);
        return Chat::fromArray($response);
    }
}

// -------------------------------------------------------------------------
//  API: Updates (Long Polling / Webhook)
// -------------------------------------------------------------------------

class Updates
{
    private Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * GET /updates — получить обновления (Long Polling)
     * 
     * @param array $params:
     *   - offset: int
     *   - limit: int (1-100)
     *   - timeout: int (секунды)
     *   - allowed_updates: array
     */
    public function get(array $params = []): array
    {
        $response = $this->client->getHttpClient()->request('GET', '/updates', [
            'query' => $params
        ]);

        $updates = [];
        foreach ($response as $item) {
            $updates[] = Update::fromArray($item);
        }
        return $updates;
    }

    /**
     * POST /webhook — установить вебхук
     * 
     * @param string $url Только HTTPS
     * @param array $options:
     *   - certificate: string (путь к сертификату)
     *   - max_connections: int (1-100)
     *   - allowed_updates: array
     */
    public function setWebhook(string $url, array $options = []): bool
    {
        if (strpos($url, 'https://') !== 0) {
            throw new ValidationException('Webhook URL must use HTTPS protocol');
        }

        $payload = array_merge(['url' => $url], $options);
        $this->client->getHttpClient()->request('POST', '/webhook', [
            'json' => $payload
        ]);
        return true;
    }

    /**
     * DELETE /webhook — удалить вебхук
     */
    public function deleteWebhook(): bool
    {
        $this->client->getHttpClient()->request('DELETE', '/webhook');
        return true;
    }

    /**
     * GET /webhook/info — получить информацию о текущем вебхуке
     */
    public function getWebhookInfo(): array
    {
        return $this->client->getHttpClient()->request('GET', '/webhook/info');
    }
}

// -------------------------------------------------------------------------
//  Главный клиент
// -------------------------------------------------------------------------

class Client
{
    private HttpClientInterface $httpClient;

    // API секции
    public Me $me;
    public Messages $messages;
    public Chats $chats;
    public Updates $updates;

    public function __construct(Config $config, ?HttpClientInterface $httpClient = null)
    {
        $this->httpClient = $httpClient ?? new CurlHttpClient($config);

        $this->me = new Me($this);
        $this->messages = new Messages($this);
        $this->chats = new Chats($this);
        $this->updates = new Updates($this);
    }

    public function getHttpClient(): HttpClientInterface
    {
        return $this->httpClient;
    }
}
?>
