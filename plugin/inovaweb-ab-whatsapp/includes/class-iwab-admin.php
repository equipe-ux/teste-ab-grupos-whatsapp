<?php
/**
 * Painel: lista de testes, editor (nome, link, versões e a fila de grupos de cada uma), resultados e a chave da
 * integração com o n8n. Formulários comuns enviados para o admin-post.php, sem build de JS.
 */

if (!defined('ABSPATH')) {
    exit;
}

class IWAB_Admin
{
    const PAGE = 'iwab';

    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_action('admin_post_iwab_save', [self::class, 'handle_save']);
        add_action('admin_post_iwab_delete', [self::class, 'handle_delete']);
        add_action('admin_post_iwab_reset', [self::class, 'handle_reset']);
        add_action('admin_post_iwab_new_key', [self::class, 'handle_new_key']);
        add_filter('plugin_action_links_' . plugin_basename(IWAB_FILE), [self::class, 'action_links']);
    }

    public static function menu(): void
    {
        add_menu_page('Teste A/B WhatsApp', 'Teste A/B WhatsApp', 'manage_options', self::PAGE, [self::class, 'render'], 'dashicons-whatsapp', 58);
    }

    public static function assets(string $hook): void
    {
        if ($hook !== 'toplevel_page_' . self::PAGE) {
            return;
        }
        wp_enqueue_style('iwab-admin', IWAB_URL . 'assets/admin.css', [], IWAB_VERSION);
        wp_enqueue_script('iwab-admin', IWAB_URL . 'assets/admin.js', [], IWAB_VERSION, true);
    }

    public static function action_links(array $links): array
    {
        array_unshift($links, '<a href="' . esc_url(self::url()) . '">Testes</a>');
        return $links;
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $action = self::query('action');
        echo '<div class="wrap iwab">';
        if ($action === 'edit' || $action === 'new') {
            self::render_edit($action === 'edit' ? (int) self::query('id') : 0);
        } else {
            self::render_list();
        }
        echo '</div>';
    }

    private static function render_list(): void
    {
        $tests = IWAB_Tests::all();
        echo '<h1 class="wp-heading-inline">Teste A/B WhatsApp</h1> ';
        echo '<a href="' . esc_url(self::url(['action' => 'new'])) . '" class="page-title-action">Novo teste</a>';
        echo '<hr class="wp-header-end">';
        self::notices();
        echo '<p class="iwab-intro">Cada teste tem <strong>um link só</strong> e várias versões (A, B, C…). Cada versão tem uma fila de grupos: '
            . 'o link do SendFlow ou convites do WhatsApp. Quem clica cai numa versão, na proporção do tráfego, e no grupo que está recebendo nela; '
            . 'o mesmo IP volta sempre para o mesmo grupo. Os links dos grupos nunca aparecem no site.</p>';

        if (!$tests) {
            echo '<div class="iwab-empty"><p>Nenhum teste criado ainda.</p>'
                . '<p><a class="button button-primary" href="' . esc_url(self::url(['action' => 'new'])) . '">Criar o primeiro teste</a></p></div>';
        } else {
            echo '<table class="widefat striped iwab-table"><thead><tr>'
                . '<th>Teste</th><th>Link</th><th class="num">Versões no ar</th><th class="num">Grupos com vaga</th><th class="num">Pessoas</th><th class="num">Cliques</th><th></th>'
                . '</tr></thead><tbody>';
            foreach ($tests as $test) {
                $edit = self::url(['action' => 'edit', 'id' => $test->id]);
                $open = (int) $test->groups_open . ' de ' . (int) $test->groups_total;
                if ($test->groups_total && !$test->groups_open) {
                    $open = '<span class="iwab-status is-full">Todos cheios</span>';
                }
                echo '<tr>'
                    . '<td><strong><a href="' . esc_url($edit) . '">' . esc_html($test->name) . '</a></strong></td>'
                    . '<td>' . self::copy_link(IWAB_Router::link($test->slug)) . '</td>'
                    . '<td class="num">' . (int) $test->versions_running . ' de ' . (int) $test->versions_total . '</td>'
                    . '<td class="num">' . $open . '</td>'
                    . '<td class="num">' . esc_html(number_format_i18n($test->people)) . '</td>'
                    . '<td class="num">' . esc_html(number_format_i18n($test->clicks)) . '</td>'
                    . '<td class="iwab-actions"><a href="' . esc_url($edit) . '">Editar</a> | '
                    . '<a class="iwab-danger" href="' . esc_url(self::delete_url((int) $test->id)) . '" data-confirm="'
                    . esc_attr('Excluir o teste "' . $test->name . '" e os resultados dele? O link para de funcionar.') . '">Excluir</a></td>'
                    . '</tr>';
            }
            echo '</tbody></table>';
        }
        self::ip_box();
        self::api_box();
    }

    private static function render_edit(int $id): void
    {
        $test = $id ? IWAB_Tests::get($id) : null;
        if ($id && !$test) {
            echo '<h1>Teste não encontrado</h1><p><a href="' . esc_url(self::url()) . '">← Todos os testes</a></p>';
            return;
        }

        // Formulário que voltou com erro: mostra o que a pessoa digitou, não o que está no banco.
        $data = null;
        $errors = [];
        $saved = get_transient(self::form_key());
        if (is_array($saved) && (int) ($saved['data']['id'] ?? 0) === $id) {
            delete_transient(self::form_key());
            $data = $saved['data'];
            $errors = $saved['errors'];
        }
        if ($data === null) {
            $data = [
                'id'       => $id,
                'name'     => $test->name ?? '',
                'slug'     => $test->slug ?? '',
                'full_at'  => $test ? IWAB_Tests::full_at($test) : 1000,
                'versions' => [],
            ];
            foreach ($test ? IWAB_Tests::structure($id, $test) : [] as $version) {
                $row = ['id' => $version->id, 'name' => $version->name, 'weight' => $version->weight, 'groups' => []];
                foreach ($version->groups as $group) {
                    $row['groups'][] = ['id' => $group->id, 'url' => $group->url, 'max_people' => $group->max_people];
                }
                $data['versions'][] = $row;
            }
            if (!$data['versions']) {
                $data['versions'] = [['groups' => [[]]], ['groups' => [[]]]];
            }
        }

        echo '<p class="iwab-back"><a href="' . esc_url(self::url()) . '">← Todos os testes</a></p>';
        echo '<h1>' . ($test ? 'Editar teste' : 'Novo teste') . '</h1>';
        self::notices($errors);
        if ($test) {
            echo '<p class="iwab-current">Link do teste: ' . self::copy_link(IWAB_Router::link($test->slug)) . '</p>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="iwab-form">';
        wp_nonce_field('iwab_save');
        echo '<input type="hidden" name="action" value="iwab_save">';
        echo '<input type="hidden" name="id" value="' . (int) $id . '">';

        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="iwab-name">Nome do teste</label></th><td>'
            . '<input type="text" id="iwab-name" name="name" class="regular-text" value="' . esc_attr((string) $data['name']) . '" placeholder="Ex.: Lançamento março — grupos">'
            . '<p class="description">Só aparece aqui no painel.</p></td></tr>';
        echo '<tr><th scope="row"><label for="iwab-slug">Link</label></th><td>'
            . '<div class="iwab-slug"><code>' . esc_html(IWAB_Router::base()) . '</code>'
            . '<input type="text" id="iwab-slug" name="slug" value="' . esc_attr((string) $data['slug']) . '" placeholder="lancamento-marco"></div>'
            . '<p class="description">É este link que vai no botão da página. Vazio: usa o nome do teste.</p></td></tr>';
        echo '<tr><th scope="row"><label for="iwab-full-at">Grupo cheio com</label></th><td>'
            . '<input type="number" id="iwab-full-at" name="full_at" min="1" max="' . IWAB_Tests::WHATSAPP_MAX . '" class="small-text" value="' . (int) $data['full_at'] . '"> membros'
            . '<p class="description">Vale para a contagem real que o n8n manda (WAHA). O WhatsApp aceita até ' . IWAB_Tests::WHATSAPP_MAX . ' por grupo.</p></td></tr>';
        echo '</tbody></table>';

        echo '<h2>Versões</h2>';
        echo '<div class="description iwab-help"><p><strong>Tráfego</strong>: a divisão entre as versões é proporcional (50 e 50 = metade para cada; 70 e 30 = 70/30). '
            . '<strong>0% pausa a versão</strong>: ninguém novo vai para ela e quem era dela cai em outra no próximo clique.</p>'
            . '<p><strong>Grupos</strong>: a fila da versão, em ordem. Enquanto o 1º tem vaga, todo mundo da versão vai para ele; encheu, passa para o 2º, e assim por diante. '
            . 'Pode ser o link do SendFlow (ele cuida da lotação sozinho) ou convites do WhatsApp (https://chat.whatsapp.com/…).</p>'
            . '<p><strong>Limite de envios</strong>: quantas pessoas o plugin manda para o grupo antes de considerá-lo cheio (vazio = sem limite). '
            . 'Como nem todo mundo que clica entra, o grupo fica com menos gente que esse número. Com o n8n ligado, vale também o "cheio com N membros" acima.</p></div>';

        echo '<div id="iwab-versions">';
        foreach (array_values((array) $data['versions']) as $vi => $version) {
            echo self::version_block((string) $vi, is_array($version) ? $version : []);
        }
        echo '</div>';
        echo '<p><button type="button" class="button" id="iwab-add-version">+ Adicionar versão</button></p>';
        echo '<template id="iwab-version-tpl">' . self::version_block('__v__', ['groups' => [[]]]) . '</template>';
        echo '<template id="iwab-group-tpl">' . self::group_row('__v__', '__g__', []) . '</template>';

        submit_button($test ? 'Salvar teste' : 'Criar teste');
        echo '</form>';

        if ($test) {
            self::render_results($test);
        }
    }

    private static function version_block(string $vi, array $version): string
    {
        $name = 'versions[' . $vi . ']';
        $weight = isset($version['weight']) && $version['weight'] !== '' ? (int) $version['weight'] : 50;
        $letter = ctype_digit($vi) ? IWAB_Tests::letter((int) $vi) : '';
        $rows = '';
        foreach (array_values((array) ($version['groups'] ?? [])) as $gi => $group) {
            $rows .= self::group_row($vi, (string) $gi, is_array($group) ? $group : []);
        }
        return '<div class="iwab-version" data-v="' . esc_attr($vi) . '">'
            . '<div class="iwab-version-head">'
            . '<span class="iwab-letter">' . esc_html($letter) . '</span>'
            . '<input type="hidden" name="' . $name . '[id]" value="' . (int) ($version['id'] ?? 0) . '">'
            . '<input type="text" name="' . $name . '[name]" value="' . esc_attr((string) ($version['name'] ?? '')) . '" placeholder="Nome da versão (opcional), ex.: Grupo com vídeo" class="iwab-version-name">'
            . '<label class="iwab-weight-label">Tráfego <input type="number" min="0" max="100" step="1" name="' . $name . '[weight]" value="' . $weight . '" class="small-text iwab-weight"></label> '
            . '<span class="iwab-share" aria-live="polite"></span>'
            . '<button type="button" class="button-link iwab-remove-version iwab-danger">Remover versão</button>'
            . '</div>'
            . '<table class="widefat iwab-groups"><thead><tr>'
            . '<th class="col-label">Grupo</th><th>Link (SendFlow ou convite do WhatsApp)</th><th class="col-limit">Limite de envios</th><th class="col-remove"></th>'
            . '</tr></thead><tbody class="iwab-group-rows">' . $rows . '</tbody></table>'
            . '<p class="iwab-add-group-wrap"><button type="button" class="button button-small iwab-add-group">+ Adicionar grupo</button></p>'
            . '</div>';
    }

    private static function group_row(string $vi, string $gi, array $group): string
    {
        $name = 'versions[' . $vi . '][groups][' . $gi . ']';
        $max = (int) ($group['max_people'] ?? 0);
        return '<tr>'
            . '<td class="col-label"><span class="iwab-group-label"></span>'
            . '<input type="hidden" name="' . $name . '[id]" value="' . (int) ($group['id'] ?? 0) . '"></td>'
            . '<td><input type="text" inputmode="url" name="' . $name . '[url]" value="' . esc_attr((string) ($group['url'] ?? '')) . '" placeholder="https://chat.whatsapp.com/... ou link do SendFlow" class="iwab-full"></td>'
            . '<td class="col-limit"><input type="number" min="0" name="' . $name . '[max_people]" value="' . ($max > 0 ? $max : '') . '" placeholder="sem" class="small-text"></td>'
            . '<td class="col-remove"><button type="button" class="button-link iwab-remove-group iwab-danger">Remover</button></td>'
            . '</tr>';
    }

    private static function render_results(object $test): void
    {
        $id = (int) $test->id;
        $versions = IWAB_Tests::structure($id, $test);
        $full_at = IWAB_Tests::full_at($test);
        $total_people = 0;
        $total_weight = 0;
        foreach ($versions as $version) {
            $total_people += $version->people;
            $total_weight += max(0, (int) $version->weight);
        }

        echo '<h2>Resultados</h2>';
        echo '<p class="description">Enviadas = IPs diferentes mandados para o grupo. Membros = contagem real que o n8n mandou (WAHA). Cliques = todos os acessos, contando repetições.</p>';
        echo '<table class="widefat iwab-table iwab-results"><thead><tr>'
            . '<th>Grupo</th><th>Situação</th><th class="num">Enviadas</th><th class="num">Membros</th><th class="num">Cliques</th>'
            . '</tr></thead><tbody>';
        foreach ($versions as $version) {
            $weight = (int) $version->weight;
            $head = $version->letter . ($version->name !== '' ? ' · ' . $version->name : '');
            $split = 'pessoas: ' . number_format_i18n($version->people) . ' (' . self::percent($version->people, $total_people) . ' real · '
                . ($weight > 0 ? self::percent($weight, $total_weight) : '0%') . ' planejado)';
            echo '<tr class="iwab-version-row"><th colspan="5"><span class="iwab-letter">' . esc_html($version->letter) . '</span> <strong>' . esc_html($head) . '</strong>'
                . ' <span class="iwab-muted">— ' . esc_html($split) . '</span>'
                . ($weight > 0 ? '' : ' <span class="iwab-status is-off">Pausada</span>') . '</th></tr>';
            foreach ($version->groups as $group) {
                if ($group->full) {
                    $status = '<span class="iwab-status is-full">Cheio</span>';
                } elseif ($group->open) {
                    $status = '<span class="iwab-status is-on">Recebendo</span>' . ($group->filling ? ' <span class="iwab-status is-filling">Enchendo</span>' : '');
                } else {
                    $status = '<span class="iwab-status is-off">Na fila</span>';
                }
                $max = (int) $group->max_people;
                $sent = number_format_i18n($group->sent) . ($max > 0 ? ' / ' . number_format_i18n($max) : '');
                if ($group->members === null) {
                    $members = '<span class="iwab-muted">—</span>';
                } else {
                    $ago = human_time_diff((int) strtotime($group->members_at . ' UTC'), time());
                    $members = esc_html(number_format_i18n($group->members) . ' / ' . number_format_i18n($full_at))
                        . '<br><span class="iwab-muted">há ' . esc_html($ago) . '</span>';
                }
                echo '<tr>'
                    . '<td><strong>' . esc_html(IWAB_Tests::group_label($version, $group)) . '</strong><br><span class="iwab-muted iwab-url">' . esc_html($group->url) . '</span></td>'
                    . '<td>' . $status . '</td>'
                    . '<td class="num">' . esc_html($sent) . '</td>'
                    . '<td class="num">' . $members . '</td>'
                    . '<td class="num">' . esc_html(number_format_i18n($group->clicks)) . '</td>'
                    . '</tr>';
            }
        }
        echo '</tbody></table>';

        echo '<div class="iwab-danger-zone">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('iwab_reset_' . $id);
        echo '<input type="hidden" name="action" value="iwab_reset"><input type="hidden" name="id" value="' . $id . '">';
        echo '<button type="submit" class="button" data-confirm="Zerar os resultados deste teste? Todo mundo vai ser redistribuído no próximo clique (quem já clicou pode cair em outro grupo).">Zerar resultados</button>';
        echo '</form>';
        echo '<a class="button iwab-danger" href="' . esc_url(self::delete_url($id)) . '" data-confirm="'
            . esc_attr('Excluir o teste "' . $test->name . '" e os resultados dele? O link para de funcionar.') . '">Excluir teste</a>';
        echo '</div>';
    }

    /** IP que o plugin enxerga no acesso do próprio administrador: se não for o dele, a separação por IP não funciona. */
    private static function ip_box(): void
    {
        $ip = IWAB_IP::client();
        echo '<div class="iwab-box"><h2>IP detectado</h2><p>Seu acesso agora: <code>' . esc_html($ip !== '' ? $ip : 'nenhum') . '</code>. '
            . 'Confira num site de "qual é meu IP": se for diferente, o plugin está vendo o IP do servidor e todo mundo cairia no mesmo grupo.</p>';
        $forwarded = IWAB_IP::forwarded();
        if ($forwarded !== '' && $forwarded !== $ip) {
            echo '<div class="notice notice-warning inline"><p>Este site está atrás de um proxy que o plugin não reconhece; ele informou o IP <code>'
                . esc_html($forwarded) . '</code>. Se esse for o seu IP de verdade, acrescente <code>define(\'IWAB_TRUST_FORWARDED\', true);</code> no <code>wp-config.php</code>.</p></div>';
        }
        echo '</div>';
    }

    /** Dados que vão no n8n: endereços da API e a chave. */
    private static function api_box(): void
    {
        $base = rest_url(IWAB_API::NS);
        echo '<div class="iwab-box"><h2>Integração com o n8n (contagem real de membros)</h2>'
            . '<p>O n8n consulta os grupos no WAHA de tempos em tempos e manda o número de membros para cá. Grupo que chega no "cheio com N membros" do teste para de receber gente nova.</p>'
            . '<table class="iwab-api"><tbody>'
            . '<tr><th>Listar grupos (GET)</th><td>' . self::copy_link($base . '/groups') . '</td></tr>'
            . '<tr><th>Enviar membros (POST)</th><td>' . self::copy_link($base . '/groups/{id}') . ' <span class="iwab-muted">corpo: {"members": 987, "jid": "…@g.us"}</span></td></tr>'
            . '<tr><th>Chave (cabeçalho X-IWAB-Key)</th><td>' . self::copy_link(IWAB_API::key()) . '</td></tr>'
            . '</tbody></table>'
            . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('iwab_new_key');
        echo '<input type="hidden" name="action" value="iwab_new_key">'
            . '<button type="submit" class="button" data-confirm="Gerar uma chave nova? O n8n para de conseguir enviar até você trocar a chave lá também.">Gerar nova chave</button>'
            . '</form></div>';
    }

    public static function handle_save(): void
    {
        self::guard('iwab_save');
        $input = wp_unslash($_POST);
        $versions = [];
        foreach (is_array($input['versions'] ?? null) ? $input['versions'] : [] as $version) {
            if (is_array($version)) {
                $version['groups'] = is_array($version['groups'] ?? null) ? array_values($version['groups']) : [];
                $versions[] = $version;
            }
        }
        $data = [
            'id'       => (int) ($input['id'] ?? 0),
            'name'     => (string) ($input['name'] ?? ''),
            'slug'     => (string) ($input['slug'] ?? ''),
            'full_at'  => (string) ($input['full_at'] ?? ''),
            'versions' => $versions,
        ];
        $result = IWAB_Tests::save($data);
        if (is_wp_error($result)) {
            set_transient(self::form_key(), ['data' => $data, 'errors' => $result->get_error_messages()], 10 * MINUTE_IN_SECONDS);
            wp_safe_redirect(self::url($data['id'] ? ['action' => 'edit', 'id' => $data['id']] : ['action' => 'new']));
            exit;
        }
        wp_safe_redirect(self::url(['action' => 'edit', 'id' => $result, 'msg' => 'saved']));
        exit;
    }

    public static function handle_delete(): void
    {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        self::guard('iwab_delete_' . $id);
        IWAB_Tests::delete($id);
        wp_safe_redirect(self::url(['msg' => 'deleted']));
        exit;
    }

    public static function handle_reset(): void
    {
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        self::guard('iwab_reset_' . $id);
        IWAB_Tests::reset($id);
        wp_safe_redirect(self::url(['action' => 'edit', 'id' => $id, 'msg' => 'reset']));
        exit;
    }

    public static function handle_new_key(): void
    {
        self::guard('iwab_new_key');
        IWAB_API::new_key();
        wp_safe_redirect(self::url(['msg' => 'key']));
        exit;
    }

    private static function guard(string $nonce_action): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Você não tem permissão para isso.', '', ['response' => 403]);
        }
        check_admin_referer($nonce_action);
    }

    private static function notices(array $errors = []): void
    {
        $messages = [
            'saved'   => 'Teste salvo.',
            'deleted' => 'Teste excluído.',
            'reset'   => 'Resultados zerados: todo mundo vai ser redistribuído no próximo clique.',
            'key'     => 'Chave nova gerada. Troque também no n8n.',
        ];
        $msg = self::query('msg');
        if (isset($messages[$msg])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($messages[$msg]) . '</p></div>';
        }
        if ($errors) {
            echo '<div class="notice notice-error"><p>' . implode('<br>', array_map('esc_html', $errors)) . '</p></div>';
        }
    }

    private static function copy_link(string $text): string
    {
        return '<span class="iwab-link"><code>' . esc_html($text) . '</code> '
            . '<button type="button" class="button button-small iwab-copy" data-copy="' . esc_attr($text) . '">Copiar</button></span>';
    }

    private static function percent(int $part, int $total): string
    {
        return $total > 0 ? number_format_i18n($part * 100 / $total, 1) . '%' : '—';
    }

    private static function url(array $args = []): string
    {
        return add_query_arg(array_merge(['page' => self::PAGE], $args), admin_url('admin.php'));
    }

    private static function delete_url(int $id): string
    {
        return wp_nonce_url(admin_url('admin-post.php?action=iwab_delete&id=' . $id), 'iwab_delete_' . $id);
    }

    private static function form_key(): string
    {
        return 'iwab_form_' . get_current_user_id();
    }

    private static function query(string $key): string
    {
        return isset($_GET[$key]) && is_string($_GET[$key]) ? sanitize_key(wp_unslash($_GET[$key])) : '';
    }
}
