<?php
/**
 * @package WCOSR
 */

declare(strict_types=1);

namespace WCOSR\Wp;

/**
 * HttpErpClient transport on top of wp_remote_request(). Redirects are not followed:
 * a signed body must only ever go to the URL the admin configured.
 */
final class WpTransport
{
    /**
     * @param array<string,string> $headers
     * @return array{status:int,headers:array<string,string>,body:string}|array{error:string}
     */
    public function __invoke(string $method, string $url, array $headers, string $body, int $timeout): array
    {
        $response = wp_remote_request($url, [
            'method'      => $method,
            'headers'     => $headers,
            'body'        => $body,
            'timeout'     => $timeout,
            'redirection' => 0,
            'sslverify'   => true,
            'blocking'    => true,
        ]);

        if (is_wp_error($response)) {
            return ['error' => $response->get_error_message()];
        }

        $raw = wp_remote_retrieve_headers($response);
        $all = is_object($raw) && method_exists($raw, 'getAll') ? $raw->getAll() : (array) $raw;

        $flat = [];
        foreach ($all as $name => $value) {
            $flat[strtolower((string) $name)] = is_array($value) ? implode(', ', $value) : (string) $value;
        }

        return [
            'status'  => (int) wp_remote_retrieve_response_code($response),
            'headers' => $flat,
            'body'    => (string) wp_remote_retrieve_body($response),
        ];
    }
}
