<?php
/**
 * O link do teste: /grupo/{slug} (ou /?iwab={slug} com links permanentes simples).
 *
 * Roda em plugins_loaded: não carrega tema nem passa pelo init. Nada aqui pode depender de tradução, post types
 * ou qualquer coisa que só exista depois do init.
 */

if (!defined('ABSPATH')) {
    exit;
}

class IWAB_Router
{
    const PATH = 'grupo';
    const QUERY = 'iwab';

    /** Começo do link de qualquer teste (o painel mostra isto antes do campo do slug). */
    public static function base(): string
    {
        if (get_option('permalink_structure')) {
            return home_url('/' . self::PATH . '/');
        }
        // Sem links permanentes o servidor nem entrega /grupo/… ao WordPress.
        return home_url('/?' . self::QUERY . '=');
    }

    public static function link(string $slug): string
    {
        return self::base() . $slug;
    }

    public static function maybe_handle(): void
    {
        $slug = self::requested_slug();
        if ($slug === '') {
            return;
        }
        $test = IWAB_Tests::find_by_slug($slug);
        if (!$test) {
            return; // o WordPress segue e responde normalmente (404 ou a página que existir)
        }

        self::no_cache();

        $ip = IWAB_IP::client();
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        $method = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
        // Pré-visualização de link (WhatsApp, Facebook…) e robôs seguem o redirecionamento: se recebessem grupo,
        // o convite apareceria na prévia da mensagem e o IP do robô entraria na conta.
        if ($ip === '' || $method !== 'GET' || IWAB_IP::is_bot($user_agent)) {
            self::page('Abra este link no navegador do celular para entrar no grupo.');
        }

        $group = IWAB_Tests::resolve($test, IWAB_IP::key($ip));
        if (!$group) {
            self::page('Os grupos estão cheios no momento. Tente novamente mais tarde.');
        }
        wp_redirect($group->url, 302, 'INOVAWEB Teste A/B WhatsApp');
        exit;
    }

    private static function requested_slug(): string
    {
        if (isset($_GET[self::QUERY])) {
            return is_string($_GET[self::QUERY]) ? sanitize_title(wp_unslash($_GET[self::QUERY])) : '';
        }
        $uri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        $path = (string) wp_parse_url($uri, PHP_URL_PATH);
        // Site em subpasta: /blog/grupo/x.
        $home = rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/') . '/';
        if ($path === '' || strpos($path, $home) !== 0) {
            return '';
        }
        if (!preg_match('#^' . self::PATH . '/([a-z0-9_-]+)/?$#i', substr($path, strlen($home)), $m)) {
            return '';
        }
        return strtolower($m[1]);
    }

    /** Resposta em cache = todo mundo no mesmo grupo. Vale para WP Rocket, LiteSpeed, W3TC e CDN. */
    private static function no_cache(): void
    {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        nocache_headers();
        header('X-LiteSpeed-Cache-Control: no-cache');
        header('CDN-Cache-Control: no-store');
    }

    /** Página mínima sem link nenhum (robôs, todos os grupos cheios). */
    private static function page(string $message): void
    {
        status_header(200);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow');
        echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow"><title>Grupo no WhatsApp</title></head>'
            . '<body style="font-family:system-ui,-apple-system,sans-serif;max-width:480px;margin:20vh auto;padding:0 16px;text-align:center;color:#1d2327">'
            . '<p>' . esc_html($message) . '</p></body></html>';
        exit;
    }
}
