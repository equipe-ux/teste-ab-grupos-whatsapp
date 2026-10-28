<?php
/**
 * IP do visitante e detecção de robôs.
 *
 * O IP é a chave do teste: mesmo IP, mesmo grupo. Por isso cabeçalho de proxy (que qualquer um consegue mandar)
 * só é aceito quando a conexão veio de fato de um proxy. Se fosse aceito sempre, quem quer pegar todos os grupos
 * trocaria de grupo só inventando um X-Forwarded-For a cada acesso.
 */

if (!defined('ABSPATH')) {
    exit;
}

class IWAB_IP
{
    /** Faixas da Cloudflare (https://www.cloudflare.com/ips/). Só vindo delas o CF-Connecting-IP é confiável. */
    const CLOUDFLARE = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
        '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
        '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
        '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    /**
     * Pré-visualizadores de link (o do próprio WhatsApp incluso), crawlers e scripts.
     * "[a-z0-9]bot[/-;)]" pega Googlebot/, bingbot/… sem confundir com celulares "CUBOT X30".
     */
    const BOT_PATTERN = '/[a-z0-9]bot[\/\-;)]|\bbot\b|crawl|spider|slurp|preview|facebookexternalhit|facebookcatalog|meta-external|meta-webindexer|whatsapp\/|telegrambot|twitterbot|linkedinbot|slackbot|discordbot|skypeuripreview|google-inspectiontool|googleother|adsbot|lighthouse|pagespeed|headlesschrome|phantomjs|puppeteer|playwright|selenium|python-|curl\/|wget\/|go-http-client|java\/|okhttp|axios\/|node-fetch|libwww|httpclient|scrapy|ahrefs|semrush|yandex|baiduspider|bytespider|gptbot|claudebot|perplexitybot|applebot/i';

    public static function is_bot(string $user_agent): bool
    {
        return $user_agent === '' || (bool) preg_match(self::BOT_PATTERN, $user_agent);
    }

    public static function client(): string
    {
        $remote = self::server('REMOTE_ADDR');
        $ip = filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '';
        if ($ip === '') {
            return '';
        }

        $cf = self::server('HTTP_CF_CONNECTING_IP');
        if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP) && self::in_ranges($ip, self::CLOUDFLARE)) {
            return $cf;
        }
        // Conexão de IP interno = proxy na própria hospedagem (nginx na frente do Apache, por exemplo).
        // IWAB_TRUST_FORWARDED no wp-config.php é para proxy de hospedagem com IP público que o plugin não reconhece.
        if (self::is_private($ip) || (defined('IWAB_TRUST_FORWARDED') && IWAB_TRUST_FORWARDED)) {
            $forwarded = self::forwarded();
            if ($forwarded !== '') {
                return $forwarded;
            }
        }
        return $ip;
    }

    /** IP que um proxy repassou. No X-Forwarded-For vale o último público: os da esquerda o visitante pode inventar. */
    public static function forwarded(): string
    {
        $candidates = array_reverse(array_map('trim', explode(',', self::server('HTTP_X_FORWARDED_FOR'))));
        $candidates[] = self::server('HTTP_X_REAL_IP');
        foreach ($candidates as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP) && !self::is_private($candidate)) {
                return $candidate;
            }
        }
        return '';
    }

    /**
     * Chave gravada no banco: HMAC do IP com o salt do site (o IP em si não é guardado).
     * IPv6 usa só o prefixo /64: o celular troca o final do endereço sozinho de tempos em tempos.
     */
    public static function key(string $ip): string
    {
        $bin = (string) inet_pton($ip);
        if (strlen($bin) === 16) {
            // ::ffff:1.2.3.4 é um IPv4 escrito como IPv6; o /64 dele seria o mesmo para todo mundo.
            $mapped = substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff";
            $bin = $mapped ? substr($bin, 12) : substr($bin, 0, 8);
        }
        return hash_hmac('sha256', $bin, wp_salt('auth'));
    }

    public static function is_private(string $ip): bool
    {
        return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    private static function in_ranges(string $ip, array $ranges): bool
    {
        $bin = (string) inet_pton($ip);
        foreach ($ranges as $range) {
            list($net, $bits) = explode('/', $range);
            $net_bin = (string) inet_pton($net);
            if (strlen($net_bin) !== strlen($bin)) {
                continue;
            }
            $bits = (int) $bits;
            $bytes = intdiv($bits, 8);
            if (substr($bin, 0, $bytes) !== substr($net_bin, 0, $bytes)) {
                continue;
            }
            $rest = $bits % 8;
            if ($rest === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $rest)) & 0xFF;
            if ((ord($bin[$bytes]) & $mask) === (ord($net_bin[$bytes]) & $mask)) {
                return true;
            }
        }
        return false;
    }

    private static function server(string $key): string
    {
        return isset($_SERVER[$key]) && is_string($_SERVER[$key]) ? trim(wp_unslash($_SERVER[$key])) : '';
    }
}
