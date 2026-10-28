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
        echo '<header class="iwab-hero">'
            . '<div class="iwab-hero-text"><p class="iwab-eyebrow">WhatsApp · Teste A/B</p>'
            . '<h1>Teste A/B WhatsApp</h1>'
            . '<p class="iwab-lede">Cada teste tem <strong>um link só</strong> e várias versões (A, B, C…), cada uma com a sua fila de grupos. '
            . 'Quem clica cai numa versão, na proporção do tráfego, e no grupo que está recebendo nela; o mesmo IP volta sempre para o mesmo grupo. '
            . 'Os links dos grupos nunca aparecem no site.</p></div>'
            . '<a href="' . esc_url(self::url(['action' => 'new'])) . '" class="iwab-btn iwab-btn-light">+ Novo teste</a>'
            . '</header>';
        echo '<hr class="wp-header-end">';
        self::notices();

        if (!$tests) {
            echo '<div class="iwab-panel iwab-empty"><div class="iwab-empty-mark" aria-hidden="true"></div>'
                . '<h2>Nenhum teste criado ainda</h2><p>Crie um teste, cole os convites dos grupos e use o link dele no botão da página.</p>'
                . '<p><a class="iwab-btn iwab-btn-primary" href="' . esc_url(self::url(['action' => 'new'])) . '">Criar o primeiro teste</a></p></div>';
        } else {
            echo '<div class="iwab-cards">';
            foreach ($tests as $test) {
                $edit = self::url(['action' => 'edit', 'id' => $test->id]);
                $all_full = $test->groups_total && !$test->groups_open;
                $chip = $all_full
                    ? '<span class="iwab-status is-full">Todos cheios</span>'
                    : ((int) $test->versions_running ? '<span class="iwab-status is-on">No ar</span>' : '<span class="iwab-status is-off">Pausado</span>');
                echo '<article class="iwab-card iwab-test-card">'
                    . '<div class="iwab-card-head"><h2><a href="' . esc_url($edit) . '">' . esc_html($test->name) . '</a></h2>' . $chip . '</div>'
                    . self::copy_link(IWAB_Router::link($test->slug))
                    . '<dl class="iwab-stats">'
                    . self::stat('Versões no ar', (int) $test->versions_running, (int) $test->versions_total)
                    . self::stat('Grupos com vaga', (int) $test->groups_open, (int) $test->groups_total)
                    . self::stat('Pessoas', (int) $test->people)
                    . self::stat('Cliques', (int) $test->clicks)
                    . '</dl>'
                    . '<div class="iwab-card-foot"><a class="iwab-btn iwab-btn-primary" href="' . esc_url($edit) . '">Abrir teste</a>'
                    . '<a class="iwab-text-danger" href="' . esc_url(self::delete_url((int) $test->id)) . '" data-confirm="'
                    . esc_attr('Excluir o teste "' . $test->name . '" e os resultados dele? O link para de funcionar.') . '">Excluir</a></div>'
                    . '</article>';
            }
            echo '</div>';
        }
        echo '<div class="iwab-grid-2">';
        self::ip_box();
        self::api_box();
        echo '</div>';
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
        echo '<header class="iwab-hero iwab-hero-compact"><div class="iwab-hero-text">'
            . '<p class="iwab-eyebrow">' . ($test ? 'Editar teste' : 'Novo teste') . '</p>'
            . '<h1>' . esc_html($test ? $test->name : 'Novo teste') . '</h1>'
            . ($test ? '<div class="iwab-current"><span class="iwab-current-label">Link do teste</span>' . self::copy_link(IWAB_Router::link($test->slug)) . '</div>' : '')
            . '</div></header>';
        echo '<hr class="wp-header-end">';
        self::notices($errors);

        if ($test) {
            self::render_results($test);
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="iwab-form">';
        wp_nonce_field('iwab_save');
        echo '<input type="hidden" name="action" value="iwab_save">';
        echo '<input type="hidden" name="id" value="' . (int) $id . '">';

        echo '<section class="iwab-panel"><div class="iwab-panel-head"><div><h2>Configuração</h2>'
            . '<p class="iwab-hint">Nome, link e quando um grupo conta como cheio.</p></div></div>';
        echo '<div class="iwab-fields">';
        echo '<div class="iwab-field"><label for="iwab-name">Nome do teste</label>'
            . '<input type="text" id="iwab-name" name="name" value="' . esc_attr((string) $data['name']) . '" placeholder="Ex.: Lançamento março — grupos">'
            . '<p class="iwab-hint">Só aparece aqui no painel.</p></div>';
        echo '<div class="iwab-field iwab-field-wide"><label for="iwab-slug">Link</label>'
            . '<div class="iwab-slug"><span class="iwab-slug-base">' . esc_html(IWAB_Router::base()) . '</span>'
            . '<input type="text" id="iwab-slug" name="slug" value="' . esc_attr((string) $data['slug']) . '" placeholder="lancamento-marco"></div>'
            . '<p class="iwab-hint">É este link que vai no botão da página. Vazio: usa o nome do teste.</p></div>';
        echo '<div class="iwab-field"><label for="iwab-full-at">Grupo cheio com</label>'
            . '<div class="iwab-suffix"><input type="number" id="iwab-full-at" name="full_at" min="1" max="' . IWAB_Tests::WHATSAPP_MAX . '" value="' . (int) $data['full_at'] . '"><span>membros</span></div>'
            . '<p class="iwab-hint">Vale para a contagem real que o n8n manda (WAHA). O WhatsApp aceita até ' . IWAB_Tests::WHATSAPP_MAX . ' por grupo.</p></div>';
        echo '</div></section>';

        echo '<section class="iwab-panel"><div class="iwab-panel-head"><div><h2>Versões e filas de grupos</h2>'
            . '<p class="iwab-hint">Cada versão recebe a sua parte do tráfego e manda as pessoas para o primeiro grupo com vaga da fila.</p></div></div>';
        echo '<details class="iwab-help"><summary>Como funciona o tráfego, a fila e o limite de envios</summary>'
            . '<p><strong>Tráfego</strong>: a divisão entre as versões é proporcional (50 e 50 = metade para cada; 70 e 30 = 70/30). '
            . '<strong>0% pausa a versão</strong>: ninguém novo vai para ela e quem era dela cai em outra no próximo clique.</p>'
            . '<p><strong>Grupos</strong>: a fila da versão, em ordem. Enquanto o 1º tem vaga, todo mundo da versão vai para ele; encheu, passa para o 2º, e assim por diante. '
            . 'Pode ser o link do SendFlow (ele cuida da lotação sozinho) ou convites do WhatsApp (https://chat.whatsapp.com/…).</p>'
            . '<p><strong>Limite de envios</strong>: quantas pessoas o plugin manda para o grupo antes de considerá-lo cheio (vazio = sem limite). '
            . 'Como nem todo mundo que clica entra, o grupo fica com menos gente que esse número. Com o n8n ligado, vale também o "cheio com N membros" acima.</p></details>';

        echo '<div id="iwab-versions">';
        foreach (array_values((array) $data['versions']) as $vi => $version) {
            echo self::version_block((string) $vi, is_array($version) ? $version : []);
        }
        echo '</div>';
        echo '<p class="iwab-add-version-wrap"><button type="button" class="iwab-btn iwab-btn-ghost" id="iwab-add-version">+ Adicionar versão</button></p>';
        echo '</section>';
        echo '<template id="iwab-version-tpl">' . self::version_block('__v__', ['groups' => [[]]]) . '</template>';
        echo '<template id="iwab-group-tpl">' . self::group_row('__v__', '__g__', []) . '</template>';

        echo '<div class="iwab-savebar"><span class="iwab-hint">As mudanças só valem depois de salvar.</span>'
            . '<button type="submit" class="iwab-btn iwab-btn-primary">' . ($test ? 'Salvar teste' : 'Criar teste') . '</button></div>';
        echo '</form>';

        if ($test) {
            self::danger_zone($test);
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
            . '<label class="iwab-weight-label"><span>Tráfego</span><input type="number" min="0" max="100" step="1" name="' . $name . '[weight]" value="' . $weight . '" class="iwab-weight"></label>'
            . '<span class="iwab-share" aria-live="polite"></span>'
            . '<button type="button" class="button-link iwab-remove-version iwab-danger">Remover versão</button>'
            . '</div>'
            . '<table class="iwab-groups"><thead><tr>'
            . '<th class="col-label">Grupo</th><th>Link (SendFlow ou convite do WhatsApp)</th><th class="col-limit">Limite de envios</th><th class="col-remove"></th>'
            . '</tr></thead><tbody class="iwab-group-rows">' . $rows . '</tbody></table>'
            . '<p class="iwab-add-group-wrap"><button type="button" class="iwab-btn iwab-btn-small iwab-add-group">+ Adicionar grupo</button></p>'
            . '</div>';
    }

    private static function group_row(string $vi, string $gi, array $group): string
    {
        $name = 'versions[' . $vi . '][groups][' . $gi . ']';
        $max = (int) ($group['max_people'] ?? 0);
        return '<tr>'
            . '<td class="col-label"><span class="iwab-group-label"></span>'
            . '<input type="hidden" name="' . $name . '[id]" value="' . (int) ($group['id'] ?? 0) . '"></td>'
            . '<td class="col-url"><input type="text" inputmode="url" name="' . $name . '[url]" value="' . esc_attr((string) ($group['url'] ?? '')) . '" placeholder="https://chat.whatsapp.com/... ou link do SendFlow" class="iwab-full" aria-label="Link do grupo"></td>'
            . '<td class="col-limit"><input type="number" min="0" name="' . $name . '[max_people]" value="' . ($max > 0 ? $max : '') . '" placeholder="sem limite" aria-label="Limite de envios"></td>'
            . '<td class="col-remove"><button type="button" class="button-link iwab-remove-group iwab-danger" aria-label="Remover grupo">Remover</button></td>'
            . '</tr>';
    }

    private static function render_results(object $test): void
    {
        $id = (int) $test->id;
        $versions = array_values(IWAB_Tests::structure($id, $test));
        $full_at = IWAB_Tests::full_at($test);
        $total_people = 0;
        $total_weight = 0;
        $total_clicks = 0;
        $groups_total = 0;
        $groups_open = 0;
        $running = 0;
        foreach ($versions as $version) {
            $total_people += $version->people;
            $total_weight += max(0, (int) $version->weight);
            $has_room = false;
            foreach ($version->groups as $group) {
                $total_clicks += $group->clicks;
                $groups_total++;
                if (!$group->full) {
                    $groups_open++;
                    $has_room = true;
                }
            }
            if ((int) $version->weight > 0 && $has_room) {
                $running++;
            }
        }

        echo '<section class="iwab-panel iwab-results"><div class="iwab-panel-head"><div><h2>Resultados</h2>'
            . '<p class="iwab-hint">Enviadas = IPs diferentes mandados para o grupo. Membros = contagem real que o n8n mandou (WAHA). Cliques = todos os acessos, contando repetições.</p></div></div>';

        echo '<dl class="iwab-kpis">'
            . self::stat('Pessoas', $total_people)
            . self::stat('Cliques', $total_clicks)
            . self::stat('Versões no ar', $running, count($versions))
            . self::stat('Grupos com vaga', $groups_open, $groups_total)
            . '</dl>';

        // Divisão do tráfego: o que foi planejado (pesos) contra o que aconteceu (pessoas por versão).
        $planned = '';
        $real = '';
        $legend = '';
        foreach ($versions as $i => $version) {
            $weight = max(0, (int) $version->weight);
            $color = 'is-c' . ($i % 6);
            $label = $version->letter . ($version->name !== '' ? ' · ' . $version->name : '');
            if ($weight > 0 && $total_weight > 0) {
                $planned .= '<span class="iwab-seg ' . $color . '" style="width:' . esc_attr(self::width($weight, $total_weight)) . '" title="' . esc_attr($label . ': ' . self::percent($weight, $total_weight)) . '">' . esc_html($version->letter) . '</span>';
            }
            if ($version->people > 0) {
                $real .= '<span class="iwab-seg ' . $color . '" style="width:' . esc_attr(self::width($version->people, $total_people)) . '" title="' . esc_attr($label . ': ' . self::percent($version->people, $total_people)) . '">' . esc_html($version->letter) . '</span>';
            }
            $legend .= '<li><span class="iwab-dot ' . $color . '"></span>' . esc_html($label) . '</li>';
        }
        echo '<div class="iwab-split">'
            . '<div class="iwab-split-row"><span class="iwab-split-label">Planejado</span><div class="iwab-split-bar">' . ($planned ?: '<span class="iwab-split-empty">todas as versões pausadas</span>') . '</div></div>'
            . '<div class="iwab-split-row"><span class="iwab-split-label">Real</span><div class="iwab-split-bar">' . ($real ?: '<span class="iwab-split-empty">ninguém clicou ainda</span>') . '</div></div>'
            . '<ul class="iwab-legend">' . $legend . '</ul>'
            . '</div>';

        echo '<div class="iwab-rversions">';
        foreach ($versions as $i => $version) {
            $weight = (int) $version->weight;
            $color = 'is-c' . ($i % 6);
            $split = number_format_i18n($version->people) . ' ' . ($version->people === 1 ? 'pessoa' : 'pessoas')
                . ' · ' . self::percent($version->people, $total_people) . ' real · '
                . ($weight > 0 ? self::percent($weight, $total_weight) : '0%') . ' planejado';
            echo '<div class="iwab-rversion ' . $color . ($weight > 0 ? '' : ' is-paused') . '">'
                . '<div class="iwab-rversion-head"><span class="iwab-letter ' . $color . '">' . esc_html($version->letter) . '</span>'
                . '<div class="iwab-rversion-title"><strong>' . esc_html($version->name !== '' ? $version->name : 'Versão ' . $version->letter) . '</strong>'
                . '<span class="iwab-muted">' . esc_html($split) . '</span></div>'
                . ($weight > 0 ? '' : '<span class="iwab-status is-off">Pausada</span>') . '</div>';
            echo '<ul class="iwab-rgroups">';
            foreach ($version->groups as $group) {
                if ($group->full) {
                    $status = '<span class="iwab-status is-full">Cheio</span>';
                } elseif ($group->open && $weight > 0) {
                    $status = '<span class="iwab-status is-on">Recebendo</span>' . ($group->filling ? '<span class="iwab-status is-filling">Enchendo</span>' : '');
                } elseif ($group->open) {
                    $status = '<span class="iwab-status is-off">Parado</span>';
                } else {
                    $status = '<span class="iwab-status is-off">Na fila</span>';
                }
                $state = $group->full ? 'is-full' : ($group->filling ? 'is-filling' : 'is-ok');
                $max = (int) $group->max_people;

                if ($group->members !== null) {
                    $ago = human_time_diff((int) strtotime($group->members_at . ' UTC'), time());
                    $members = '<span class="iwab-metric-value">' . esc_html(number_format_i18n($group->members)) . '<small> / ' . esc_html(number_format_i18n($full_at)) . '</small></span>'
                        . self::bar($group->members, $full_at)
                        . '<span class="iwab-metric-note">há ' . esc_html($ago) . '</span>';
                } elseif (IWAB_Tests::invite_code((string) $group->url) === '') {
                    $members = '<span class="iwab-metric-value is-empty">—</span><span class="iwab-metric-note">o SendFlow cuida da lotação</span>';
                } else {
                    $members = '<span class="iwab-metric-value is-empty">—</span><span class="iwab-metric-note">aguardando o n8n</span>';
                }
                $sent = '<span class="iwab-metric-value">' . esc_html(number_format_i18n($group->sent))
                    . ($max > 0 ? '<small> / ' . esc_html(number_format_i18n($max)) . '</small>' : '') . '</span>'
                    . ($max > 0 ? self::bar($group->sent, $max) : '<span class="iwab-metric-note">sem limite</span>');

                echo '<li class="iwab-rgroup ' . $state . ($group->open && $weight > 0 ? ' is-current' : '') . '">'
                    . '<div class="iwab-rgroup-id"><div class="iwab-rgroup-name"><strong>' . esc_html(IWAB_Tests::group_label($version, $group)) . '</strong>' . $status . '</div>'
                    . '<span class="iwab-url" title="' . esc_attr($group->url) . '">' . esc_html($group->url) . '</span></div>'
                    . '<div class="iwab-metric"><span class="iwab-metric-label">Membros</span>' . $members . '</div>'
                    . '<div class="iwab-metric"><span class="iwab-metric-label">Enviadas</span>' . $sent . '</div>'
                    . '<div class="iwab-metric iwab-metric-clicks"><span class="iwab-metric-label">Cliques</span><span class="iwab-metric-value">' . esc_html(number_format_i18n($group->clicks)) . '</span></div>'
                    . '</li>';
            }
            echo '</ul></div>';
        }
        echo '</div></section>';
    }

    private static function danger_zone(object $test): void
    {
        $id = (int) $test->id;
        echo '<section class="iwab-panel iwab-danger-zone"><div><h2>Zona de cuidado</h2>'
            . '<p class="iwab-hint">Zerar redistribui todo mundo no próximo clique (não mexe na contagem de membros). Excluir apaga o teste e o link para de funcionar.</p></div>'
            . '<div class="iwab-danger-actions">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('iwab_reset_' . $id);
        echo '<input type="hidden" name="action" value="iwab_reset"><input type="hidden" name="id" value="' . $id . '">';
        echo '<button type="submit" class="iwab-btn" data-confirm="Zerar os resultados deste teste? Todo mundo vai ser redistribuído no próximo clique (quem já clicou pode cair em outro grupo).">Zerar resultados</button>';
        echo '</form>';
        echo '<a class="iwab-btn iwab-btn-danger" href="' . esc_url(self::delete_url($id)) . '" data-confirm="'
            . esc_attr('Excluir o teste "' . $test->name . '" e os resultados dele? O link para de funcionar.') . '">Excluir teste</a>';
        echo '</div></section>';
    }

    /** IP que o plugin enxerga no acesso do próprio administrador: se não for o dele, a separação por IP não funciona. */
    private static function ip_box(): void
    {
        $ip = IWAB_IP::client();
        echo '<section class="iwab-panel iwab-box"><h2>IP detectado</h2>'
            . '<p class="iwab-ip"><code>' . esc_html($ip !== '' ? $ip : 'nenhum') . '</code></p>'
            . '<p class="iwab-hint">É o seu acesso agora. Confira num site de "qual é meu IP": se for diferente, o plugin está vendo o IP do servidor e todo mundo cairia no mesmo grupo.</p>';
        $forwarded = IWAB_IP::forwarded();
        if ($forwarded !== '' && $forwarded !== $ip) {
            echo '<div class="notice notice-warning inline"><p>Este site está atrás de um proxy que o plugin não reconhece; ele informou o IP <code>'
                . esc_html($forwarded) . '</code>. Se esse for o seu IP de verdade, acrescente <code>define(\'IWAB_TRUST_FORWARDED\', true);</code> no <code>wp-config.php</code>.</p></div>';
        }
        echo '</section>';
    }

    /** Dados que vão no n8n: endereços da API e a chave. */
    private static function api_box(): void
    {
        $base = rest_url(IWAB_API::NS);
        echo '<section class="iwab-panel iwab-box"><h2>Integração com o n8n</h2>'
            . '<p class="iwab-hint">O n8n consulta os grupos no WAHA de tempos em tempos e manda o número de membros para cá. Grupo que chega no "cheio com N membros" do teste para de receber gente nova.</p>'
            . '<dl class="iwab-api">'
            . '<div><dt>Listar grupos <span class="iwab-method">GET</span></dt><dd>' . self::copy_link($base . '/groups') . '</dd></div>'
            . '<div><dt>Enviar membros <span class="iwab-method">POST</span></dt><dd>' . self::copy_link($base . '/groups/{id}') . '<span class="iwab-hint">corpo: {"members": 987, "jid": "…@g.us"}</span></dd></div>'
            . '<div><dt>Chave <span class="iwab-method">X-IWAB-Key</span></dt><dd>' . self::copy_link(IWAB_API::key()) . '</dd></div>'
            . '</dl>'
            . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('iwab_new_key');
        echo '<input type="hidden" name="action" value="iwab_new_key">'
            . '<button type="submit" class="iwab-btn iwab-btn-small" data-confirm="Gerar uma chave nova? O n8n para de conseguir enviar até você trocar a chave lá também.">Gerar nova chave</button>'
            . '</form></section>';
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
        return '<span class="iwab-link"><code>' . esc_html($text) . '</code>'
            . '<button type="button" class="iwab-copy" data-copy="' . esc_attr($text) . '">Copiar</button></span>';
    }

    /** Número grande com rótulo (cartões e resumo). "de N" quando é parte de um total. */
    private static function stat(string $label, int $value, ?int $of = null): string
    {
        return '<div class="iwab-stat"><dt>' . esc_html($label) . '</dt><dd>' . esc_html(number_format_i18n($value))
            . ($of !== null ? '<small> de ' . esc_html(number_format_i18n($of)) . '</small>' : '') . '</dd></div>';
    }

    /** Barra de lotação: quanto do limite já foi usado (amarela a partir de 90%, vermelha no limite). */
    private static function bar(int $value, int $max): string
    {
        $state = $value >= $max ? 'is-full' : ($value >= $max * 0.9 ? 'is-filling' : 'is-ok');
        return '<span class="iwab-bar ' . esc_attr($state) . '"><span style="width:' . esc_attr(self::width($value, $max)) . '"></span></span>';
    }

    private static function width(int $part, int $total): string
    {
        $pct = $total > 0 ? min(100, max(0, $part * 100 / $total)) : 0;
        return rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.') . '%';
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
