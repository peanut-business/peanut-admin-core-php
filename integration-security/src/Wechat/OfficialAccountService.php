<?php

declare(strict_types=1);

namespace PeanutAdmin\IntegrationSecurity\Wechat;

/** Standard HTTP boundary for the public WeChat Official Account API. */
class OfficialAccountService
{
    private const TOKEN_URL = 'https://api.weixin.qq.com/cgi-bin/token';
    private const MENU_URL = 'https://api.weixin.qq.com/cgi-bin/menu/create';

    /** @var null|callable(string,string,array,string):array{0:int,1:string} */
    private $transport;

    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport;
    }

    /** @param array<int, array<string, mixed>> $menu */
    public function publishMenu(string $appId, string $appSecret, array $menu): void
    {
        if (trim($appId) === '' || trim($appSecret) === '') {
            throw new \RuntimeException('微信公众号 AppID 或 AppSecret 未配置');
        }
        $tokenUrl = self::TOKEN_URL . '?' . http_build_query(['grant_type' => 'client_credential', 'appid' => $appId, 'secret' => $appSecret], '', '&', PHP_QUERY_RFC3986);
        $tokenResult = $this->requestJson('GET', $tokenUrl);
        $accessToken = trim((string) ($tokenResult['access_token'] ?? ''));
        if ($accessToken === '') {
            throw new \RuntimeException('微信 access_token 获取失败：' . $this->wechatMessage($tokenResult));
        }
        $body = json_encode(['button' => $this->wechatButtons($menu)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $result = $this->requestJson('POST', self::MENU_URL . '?access_token=' . rawurlencode($accessToken), $body);
        if ((int) ($result['errcode'] ?? -1) !== 0) {
            throw new \RuntimeException('微信公众号菜单发布失败：' . $this->wechatMessage($result));
        }
    }

    public static function verifySignature(string $token, string $timestamp, string $nonce, string $signature): bool
    {
        if ($token === '' || $timestamp === '' || $nonce === '' || $signature === '') {
            return false;
        }
        $parts = [$token, $timestamp, $nonce];
        sort($parts, SORT_STRING);
        return hash_equals(sha1(implode('', $parts)), $signature);
    }

    /** @return array<string, string> */
    public static function parsePlainMessage(string $xml): array
    {
        if (trim($xml) === '') {
            throw new \RuntimeException('微信消息为空');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $message = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
            if ($message === false) {
                throw new \RuntimeException('微信消息格式无效');
            }
            $result = [];
            foreach ($message as $key => $value) {
                $result[(string) $key] = trim((string) $value);
            }
            return $result;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** @param array<string, string> $incoming */
    public static function textReplyXml(array $incoming, string $content): string
    {
        $to = self::xmlEscape((string) ($incoming['FromUserName'] ?? ''));
        $from = self::xmlEscape((string) ($incoming['ToUserName'] ?? ''));
        $safeContent = str_replace(']]>', ']]]]><![CDATA[>', $content);
        return '<xml><ToUserName><![CDATA[' . $to . ']]></ToUserName><FromUserName><![CDATA[' . $from . ']]></FromUserName><CreateTime>' . time() . '</CreateTime><MsgType><![CDATA[text]]></MsgType><Content><![CDATA[' . $safeContent . ']]></Content></xml>';
    }

    /** @return array<string, mixed> */
    private function requestJson(string $method, string $url, string $body = ''): array
    {
        [$status, $response] = $this->request($method, $url, $body);
        $decoded = json_decode($response, true);
        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            throw new \RuntimeException('微信接口响应无效（HTTP ' . $status . '）');
        }
        return $decoded;
    }

    /** @return array{0: int, 1: string} */
    private function request(string $method, string $url, string $body): array
    {
        if ($this->transport !== null) {
            $result = ($this->transport)($method, $url, ['Accept: application/json', 'Content-Type: application/json', 'User-Agent: PeanutAdmin/1.0'], $body);
            if (!is_array($result) || count($result) !== 2) {
                throw new \RuntimeException('微信公众号传输器返回格式无效');
            }
            return [(int) $result[0], (string) $result[1]];
        }
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('服务器未安装 cURL 扩展，无法调用微信接口');
        }
        $curl = curl_init($url);
        if ($curl === false) {
            throw new \RuntimeException('微信接口请求初始化失败');
        }
        curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_POSTFIELDS => $method === 'POST' ? $body : null, CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'User-Agent: PeanutAdmin/1.0'], CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
        $response = curl_exec($curl);
        if ($response === false) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new \RuntimeException('微信接口网络异常：' . $error);
        }
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        return [$status, (string) $response];
    }

    /** @param array<int, array<string, mixed>> $menu @return array<int, array<string, mixed>> */
    private function wechatButtons(array $menu): array
    {
        return array_map(function (array $item): array {
            $children = is_array($item['sub_button'] ?? null) ? $item['sub_button'] : [];
            if ($children !== []) {
                return ['name' => (string) $item['name'], 'sub_button' => $this->wechatButtons($children)];
            }
            $button = ['name' => (string) $item['name'], 'type' => (string) $item['type']];
            return match ($button['type']) {
                'click' => $button + ['key' => (string) $item['key']],
                'view' => $button + ['url' => (string) $item['url']],
                'miniprogram' => $button + ['url' => (string) $item['url'], 'appid' => (string) $item['appid'], 'pagepath' => (string) $item['pagepath']],
                default => throw new \RuntimeException('公众号菜单类型无效'),
            };
        }, $menu);
    }

    /** @param array<string, mixed> $result */
    private function wechatMessage(array $result): string
    {
        $message = trim((string) ($result['errmsg'] ?? $result['message'] ?? '未知错误'));
        $code = (string) ($result['errcode'] ?? '');
        return $code === '' ? $message : $code . ' ' . $message;
    }

    private static function xmlEscape(string $value): string
    {
        return str_replace(']]>', ']]]]><![CDATA[>', $value);
    }
}
