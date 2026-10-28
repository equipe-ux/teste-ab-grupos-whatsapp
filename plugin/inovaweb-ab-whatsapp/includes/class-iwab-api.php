<?php
/**
 * API para o n8n (contagem real de membros via WAHA).
 *
 *   GET  /wp-json/iwab/v1/groups           → convites de grupo de todos os testes (id, invite_code, jid, membros…)
 *   POST /wp-json/iwab/v1/groups/{id}      → {"members": 987, "jid": "1203...@g.us"} grava a contagem real
 *
 * Autenticação: cabeçalho X-IWAB-Key (ou Authorization: Bearer) com a chave mostrada no painel do plugin.
 * O plugin não chama o WAHA nem servidor nenhum: quem busca e manda os números é o n8n.
 */

if (!defined('ABSPATH')) {
    exit;
}

class IWAB_API
{
    const NS = 'iwab/v1';
    const OPTION = 'iwab_api_key';

    public static function boot(): void
    {
        add_action('rest_api_init', [self::class, 'routes']);
    }

    public static function routes(): void
    {
        register_rest_route(self::NS, '/groups', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'list_groups'],
            'permission_callback' => [self::class, 'authorized'],
        ]);
        register_rest_route(self::NS, '/groups/(?P<id>\d+)', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'update_group'],
            'permission_callback' => [self::class, 'authorized'],
            'args'                => [
                'members' => ['required' => true],
                'jid'     => ['required' => false],
            ],
        ]);
    }

    /** Chave da integração. Criada na primeira vez que alguém precisa dela. */
    public static function key(): string
    {
        $key = (string) get_option(self::OPTION, '');
        if ($key === '') {
            $key = self::new_key();
        }
        return $key;
    }

    public static function new_key(): string
    {
        $key = wp_generate_password(40, false, false);
        update_option(self::OPTION, $key, false);
        return $key;
    }

    /** @return true|WP_Error */
    public static function authorized(WP_REST_Request $request)
    {
        $sent = trim((string) $request->get_header('x_iwab_key'));
        if ($sent === '') {
            $auth = (string) $request->get_header('authorization');
            if (stripos($auth, 'Bearer ') === 0) {
                $sent = trim(substr($auth, 7));
            }
        }
        $key = (string) get_option(self::OPTION, '');
        if ($key === '' || $sent === '' || !hash_equals($key, $sent)) {
            return new WP_Error('iwab_unauthorized', 'Chave inválida. Use a chave mostrada no painel do Teste A/B WhatsApp.', ['status' => 401]);
        }
        return true;
    }

    public static function list_groups(): WP_REST_Response
    {
        return new WP_REST_Response(['groups' => IWAB_Tests::for_api()]);
    }

    /** @return WP_REST_Response|WP_Error */
    public static function update_group(WP_REST_Request $request)
    {
        $members = $request->get_param('members');
        if (!is_numeric($members) || (int) $members < 0) {
            return new WP_Error('iwab_members', 'Envie "members" com o número de membros do grupo.', ['status' => 400]);
        }
        $jid = trim((string) $request->get_param('jid'));
        if ($jid !== '' && !preg_match('/^[0-9-]+@g\.us$/', $jid)) {
            return new WP_Error('iwab_jid', '"jid" precisa ser o id do grupo no WhatsApp (ex.: 120363000000000000@g.us).', ['status' => 400]);
        }
        $id = (int) $request->get_param('id');
        if (!IWAB_Tests::set_members($id, (int) $members, $jid)) {
            return new WP_Error('iwab_not_found', 'Grupo não encontrado.', ['status' => 404]);
        }
        return new WP_REST_Response(['ok' => true, 'id' => $id, 'members' => (int) $members]);
    }
}
