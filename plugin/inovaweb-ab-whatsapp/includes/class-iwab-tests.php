<?php
/**
 * Testes, versões, grupos e a escolha do grupo de cada IP.
 *
 * Teste A/B por versões (com % de tráfego, 0% = pausada), em que cada versão tem uma
 * fila de grupos e a "fixação" é pelo IP, não por cookie: quem tenta pegar vários grupos limpando o navegador continua
 * caindo no mesmo grupo.
 *
 * Grupo cheio = bateu o limite de envios (contagem do próprio plugin) OU o número real de membros que o n8n mandou
 * (WAHA) chegou no "cheio com N membros" do teste. Cheio não recebe gente nova; quem já era dele continua indo para ele.
 */

if (!defined('ABSPATH')) {
    exit;
}

class IWAB_Tests
{
    /** Teto do WhatsApp por grupo. "Cheio com N membros" não passa disso. */
    const WHATSAPP_MAX = 1024;

    /** Lista do painel, com contagens de cada teste. */
    public static function all(): array
    {
        global $wpdb;
        $tests = $wpdb->get_results('SELECT * FROM ' . IWAB_DB::t('tests') . ' ORDER BY id DESC');
        $people = $wpdb->get_results('SELECT test_id, COUNT(*) AS people, SUM(clicks) AS clicks FROM ' . IWAB_DB::t('people') . ' GROUP BY test_id', OBJECT_K);

        foreach ($tests as $test) {
            $id = (int) $test->id;
            $versions = self::structure($id, $test);
            $test->versions_total = count($versions);
            $test->versions_running = 0;
            $test->groups_total = 0;
            $test->groups_open = 0;
            foreach ($versions as $version) {
                if ((int) $version->weight <= 0) {
                    continue; // grupo de versão pausada não recebe ninguém: não conta como vaga
                }
                $test->versions_running++;
                foreach ($version->groups as $group) {
                    $test->groups_total++;
                    $test->groups_open += $group->full ? 0 : 1;
                }
            }
            $test->people = isset($people[$id]) ? (int) $people[$id]->people : 0;
            $test->clicks = isset($people[$id]) ? (int) $people[$id]->clicks : 0;
        }
        return $tests;
    }

    public static function get(int $id): ?object
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . IWAB_DB::t('tests') . ' WHERE id = %d', $id)) ?: null;
    }

    public static function find_by_slug(string $slug): ?object
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . IWAB_DB::t('tests') . ' WHERE slug = %s', $slug)) ?: null;
    }

    /**
     * Versões do teste (ordem do painel), cada uma com ->letter, ->people e ->groups (fila em ordem). Cada grupo vem com
     * ->sent (pessoas mandadas para ele), ->clicks, ->full, ->filling (90% do limite) e ->open (é o que está recebendo).
     */
    public static function structure(int $test_id, ?object $test = null): array
    {
        global $wpdb;
        $test = $test ?: self::get($test_id);
        $full_at = $test ? self::full_at($test) : self::WHATSAPP_MAX;

        $versions = [];
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . IWAB_DB::t('versions') . ' WHERE test_id = %d ORDER BY position, id', $test_id));
        foreach ($rows as $i => $version) {
            $version->letter = self::letter($i);
            $version->people = 0;
            $version->groups = [];
            $versions[(int) $version->id] = $version;
        }

        $by_version = $wpdb->get_results($wpdb->prepare(
            'SELECT version_id, COUNT(*) AS people FROM ' . IWAB_DB::t('people') . ' WHERE test_id = %d GROUP BY version_id',
            $test_id
        ));
        foreach ($by_version as $row) {
            if (isset($versions[(int) $row->version_id])) {
                $versions[(int) $row->version_id]->people = (int) $row->people;
            }
        }

        $by_group = [];
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT group_id, COUNT(*) AS people, SUM(clicks) AS clicks FROM ' . IWAB_DB::t('people') . ' WHERE test_id = %d GROUP BY group_id',
            $test_id
        ));
        foreach ($rows as $row) {
            $by_group[(int) $row->group_id] = $row;
        }

        $groups = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . IWAB_DB::t('groups') . ' WHERE test_id = %d ORDER BY position, id', $test_id));
        foreach ($groups as $group) {
            $version_id = (int) $group->version_id;
            if (!isset($versions[$version_id])) {
                continue;
            }
            $group->sent = isset($by_group[(int) $group->id]) ? (int) $by_group[(int) $group->id]->people : 0;
            $group->clicks = isset($by_group[(int) $group->id]) ? (int) $by_group[(int) $group->id]->clicks : 0;
            $group->members = $group->members === null ? null : (int) $group->members;
            $max = (int) $group->max_people;
            $group->full = ($max > 0 && $group->sent >= $max) || ($group->members !== null && $group->members >= $full_at);
            $group->filling = !$group->full && (
                ($max > 0 && $group->sent >= $max * 0.9) || ($group->members !== null && $group->members >= $full_at * 0.9)
            );
            $group->open = false;
            $group->index = count($versions[$version_id]->groups);
            $versions[$version_id]->groups[] = $group;
        }
        foreach ($versions as $version) {
            foreach ($version->groups as $group) {
                if (!$group->full) {
                    $group->open = true;
                    break;
                }
            }
        }
        return $versions;
    }

    /** A, B, C… pela posição, como no editor de versões. */
    public static function letter(int $index): string
    {
        return $index < 26 ? chr(65 + $index) : (string) ($index + 1);
    }

    /** Nome curto do grupo no painel: A1, A2, B1… */
    public static function group_label(object $version, object $group): string
    {
        return $version->letter . ($group->index + 1);
    }

    public static function full_at(object $test): int
    {
        $full_at = (int) ($test->full_at ?? 0);
        return $full_at > 0 ? min(self::WHATSAPP_MAX, $full_at) : 1000;
    }

    /**
     * Grupo deste IP no teste. Primeira vez: escolhe a versão (pelo %) e o grupo que está recebendo nela, e grava.
     * Depois: sempre o mesmo grupo, mesmo que ele tenha enchido (a pessoa provavelmente já está lá dentro). Só troca se o
     * grupo foi removido (vai para o grupo aberto da mesma versão) ou a versão foi pausada (ganha outra versão).
     */
    public static function resolve(object $test, string $ip_key): ?object
    {
        global $wpdb;
        $table = IWAB_DB::t('people');
        $test_id = (int) $test->id;
        $now = gmdate('Y-m-d H:i:s');
        $versions = self::structure($test_id, $test);

        $groups = [];
        foreach ($versions as $version) {
            foreach ($version->groups as $group) {
                $groups[(int) $group->id] = $group;
            }
        }

        $row = $wpdb->get_row($wpdb->prepare("SELECT id, version_id, group_id FROM $table WHERE test_id = %d AND ip_hash = %s", $test_id, $ip_key));
        if ($row) {
            $version = $versions[(int) $row->version_id] ?? null;
            $running = $version && (int) $version->weight > 0;
            $group = $groups[(int) $row->group_id] ?? null;
            if ($running && $group && (int) $group->version_id === (int) $version->id) {
                $wpdb->query($wpdb->prepare("UPDATE $table SET clicks = clicks + 1, last_at = %s WHERE id = %d", $now, (int) $row->id));
                return $group;
            }
            $group = $running ? self::open_group($version) : null;
            if (!$group) {
                $group = self::place($versions);
            }
            if ($group) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE $table SET version_id = %d, group_id = %d, clicks = 1, last_at = %s WHERE id = %d",
                    (int) $group->version_id,
                    (int) $group->id,
                    $now,
                    (int) $row->id
                ));
            }
            return $group;
        }

        $group = self::place($versions);
        if (!$group) {
            return null;
        }
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $table (test_id, ip_hash, version_id, group_id, clicks, first_at, last_at) VALUES (%d, %s, %d, %d, 1, %s, %s)",
            $test_id,
            $ip_key,
            (int) $group->version_id,
            (int) $group->id,
            $now,
            $now
        ));
        if (!$inserted) {
            // Dois cliques simultâneos do mesmo IP: vale o grupo de quem gravou primeiro.
            $winner = (int) $wpdb->get_var($wpdb->prepare("SELECT group_id FROM $table WHERE test_id = %d AND ip_hash = %s", $test_id, $ip_key));
            if (isset($groups[$winner])) {
                return $groups[$winner];
            }
        }
        return $group;
    }

    private static function open_group(object $version): ?object
    {
        foreach ($version->groups as $group) {
            if ($group->open) {
                return $group;
            }
        }
        return null;
    }

    /**
     * Versão com menos gente em relação ao % dela (empate: sorteio), entre as que estão no ar e ainda têm grupo com vaga.
     * Assim a divisão real fica colada na planejada (50/50, 70/30…) desde as primeiras pessoas.
     */
    private static function place(array $versions): ?object
    {
        $best = [];
        $best_ratio = null;
        foreach ($versions as $version) {
            $weight = (int) $version->weight;
            if ($weight <= 0 || !self::open_group($version)) {
                continue;
            }
            $ratio = $version->people / $weight;
            if ($best_ratio === null || $ratio < $best_ratio) {
                $best_ratio = $ratio;
                $best = [$version];
            } elseif ($ratio == $best_ratio) {
                $best[] = $version;
            }
        }
        return $best ? self::open_group($best[array_rand($best)]) : null;
    }

    /**
     * Grava o teste, as versões e os grupos (o que sumiu do formulário é excluído).
     *
     * @return int|WP_Error id do teste
     */
    public static function save(array $input)
    {
        global $wpdb;
        $id = (int) ($input['id'] ?? 0);
        $name = sanitize_text_field((string) ($input['name'] ?? ''));
        $slug = sanitize_title((string) ($input['slug'] ?? ''));
        if ($slug === '') {
            $slug = sanitize_title($name);
        }
        $full_at = trim((string) ($input['full_at'] ?? ''));
        $full_at = $full_at === '' ? 1000 : min(self::WHATSAPP_MAX, max(1, (int) $full_at));

        $errors = new WP_Error();
        if ($name === '') {
            $errors->add('iwab', 'Dê um nome ao teste.');
        }
        if ($slug === '') {
            $errors->add('iwab', 'Informe o final do link.');
        } else {
            $taken = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . IWAB_DB::t('tests') . ' WHERE slug = %s AND id <> %d', $slug, $id));
            if ($taken) {
                $errors->add('iwab', 'Já existe outro teste com o link "' . $slug . '".');
            }
        }

        $versions = [];
        $running = 0;
        foreach ((array) ($input['versions'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $letter = self::letter(count($versions));
            $groups = [];
            foreach ((array) ($row['groups'] ?? []) as $group_row) {
                if (!is_array($group_row)) {
                    continue;
                }
                $url = trim((string) ($group_row['url'] ?? ''));
                if ($url === '') {
                    continue; // linha em branco
                }
                $clean = self::clean_url($url);
                if ($clean === '') {
                    $errors->add('iwab', 'O link do grupo ' . $letter . (count($groups) + 1) . ' não é um endereço válido (cole o link do SendFlow ou o convite do grupo).');
                }
                $groups[] = [
                    'id'         => (int) ($group_row['id'] ?? 0),
                    'url'        => $clean,
                    'max_people' => max(0, (int) ($group_row['max_people'] ?? 0)),
                ];
            }
            $version_name = sanitize_text_field((string) ($row['name'] ?? ''));
            if (!$groups && $version_name === '' && empty($row['id'])) {
                continue; // versão nova deixada em branco
            }
            if (!$groups) {
                $errors->add('iwab', 'A versão ' . $letter . ' precisa de pelo menos um grupo.');
            }
            $weight = trim((string) ($row['weight'] ?? ''));
            $weight = $weight === '' ? 50 : min(100, max(0, (int) $weight));
            $running += $weight > 0 ? 1 : 0;
            $versions[] = [
                'id'     => (int) ($row['id'] ?? 0),
                'name'   => $version_name,
                'weight' => $weight,
                'groups' => $groups,
            ];
        }
        if (!$versions) {
            $errors->add('iwab', 'Cadastre pelo menos uma versão.');
        } elseif (!$running) {
            $errors->add('iwab', 'Pelo menos uma versão precisa ter tráfego acima de 0%.');
        }
        if ($id && !self::get($id)) {
            $errors->add('iwab', 'Teste não encontrado.');
        }
        if ($errors->has_errors()) {
            return $errors;
        }

        $now = gmdate('Y-m-d H:i:s');
        $tests = IWAB_DB::t('tests');
        if ($id) {
            $wpdb->update($tests, ['name' => $name, 'slug' => $slug, 'full_at' => $full_at], ['id' => $id]);
        } else {
            if ($wpdb->insert($tests, ['name' => $name, 'slug' => $slug, 'full_at' => $full_at, 'created_at' => $now]) === false) {
                return new WP_Error('iwab', 'Não foi possível salvar o teste (erro no banco de dados).');
            }
            $id = (int) $wpdb->insert_id;
        }

        $existing_versions = array_map('intval', $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . IWAB_DB::t('versions') . ' WHERE test_id = %d', $id)));
        $existing_groups = [];
        foreach ($wpdb->get_results($wpdb->prepare('SELECT id, version_id FROM ' . IWAB_DB::t('groups') . ' WHERE test_id = %d', $id)) as $row) {
            $existing_groups[(int) $row->id] = (int) $row->version_id;
        }

        $keep_versions = [];
        $keep_groups = [];
        foreach ($versions as $position => $version) {
            $data = ['name' => $version['name'], 'weight' => $version['weight'], 'position' => $position];
            $version_id = $version['id'];
            if ($version_id && in_array($version_id, $existing_versions, true)) {
                $wpdb->update(IWAB_DB::t('versions'), $data, ['id' => $version_id]);
            } else {
                $wpdb->insert(IWAB_DB::t('versions'), $data + ['test_id' => $id, 'created_at' => $now]);
                $version_id = (int) $wpdb->insert_id;
            }
            $keep_versions[] = $version_id;

            foreach ($version['groups'] as $group_position => $group) {
                $data = ['url' => $group['url'], 'max_people' => $group['max_people'], 'position' => $group_position];
                $group_id = $group['id'];
                if ($group_id && ($existing_groups[$group_id] ?? 0) === $version_id) {
                    // Link trocado: a contagem do n8n era do grupo antigo.
                    $old_url = (string) $wpdb->get_var($wpdb->prepare('SELECT url FROM ' . IWAB_DB::t('groups') . ' WHERE id = %d', $group_id));
                    if ($old_url !== $group['url']) {
                        $data += ['jid' => '', 'members' => null, 'members_at' => null];
                    }
                    $wpdb->update(IWAB_DB::t('groups'), $data, ['id' => $group_id]);
                } else {
                    $wpdb->insert(IWAB_DB::t('groups'), $data + ['test_id' => $id, 'version_id' => $version_id, 'created_at' => $now]);
                    $group_id = (int) $wpdb->insert_id;
                }
                $keep_groups[] = $group_id;
            }
        }

        $removed_versions = array_values(array_diff($existing_versions, $keep_versions));
        if ($removed_versions) {
            $in = implode(',', array_fill(0, count($removed_versions), '%d'));
            // Quem estava numa versão excluída volta a ser "novo" e é sorteado de novo no próximo clique.
            $wpdb->query($wpdb->prepare('DELETE FROM ' . IWAB_DB::t('people') . " WHERE version_id IN ($in)", $removed_versions));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . IWAB_DB::t('versions') . " WHERE id IN ($in)", $removed_versions));
        }
        $removed_groups = array_values(array_diff(array_keys($existing_groups), $keep_groups));
        if ($removed_groups) {
            $in = implode(',', array_fill(0, count($removed_groups), '%d'));
            // Quem estava num grupo excluído continua na versão e vai para o grupo que estiver recebendo nela.
            $wpdb->query($wpdb->prepare('UPDATE ' . IWAB_DB::t('people') . " SET group_id = 0 WHERE group_id IN ($in)", $removed_groups));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . IWAB_DB::t('groups') . " WHERE id IN ($in)", $removed_groups));
        }
        return $id;
    }

    /** Link de destino (SendFlow, convite do WhatsApp ou outro). Sem "https://" colado, completa. Inválido: ''. */
    public static function clean_url(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || strpos($parts['host'], '.') === false) {
            return '';
        }
        return esc_url_raw($url, ['http', 'https']);
    }

    /** Código do convite (chat.whatsapp.com/CÓDIGO), que o n8n usa para achar o grupo no WAHA. Outros links: ''. */
    public static function invite_code(string $url): string
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || strtolower($parts['host'] ?? '') !== 'chat.whatsapp.com') {
            return '';
        }
        $code = preg_replace('#^invite/#', '', trim($parts['path'] ?? '', '/'));
        return preg_match('#^[A-Za-z0-9]{10,40}$#', $code) ? $code : '';
    }

    /** Grupos de todos os testes para o n8n: só os que são convite do WhatsApp (SendFlow cuida da própria lotação). */
    public static function for_api(): array
    {
        $out = [];
        foreach (self::all() as $test) {
            foreach (self::structure((int) $test->id, $test) as $version) {
                foreach ($version->groups as $group) {
                    $code = self::invite_code($group->url);
                    if ($code === '') {
                        continue;
                    }
                    $out[] = [
                        'id'          => (int) $group->id,
                        'test'        => $test->name,
                        'group'       => self::group_label($version, $group),
                        'invite_code' => $code,
                        'jid'         => $group->jid,
                        'members'     => $group->members,
                        'members_at'  => $group->members_at ? gmdate('c', strtotime($group->members_at . ' UTC')) : null,
                        'sent'        => $group->sent,
                        'full'        => $group->full,
                    ];
                }
            }
        }
        return $out;
    }

    /** Contagem real de membros vinda do n8n (WAHA). jid = id do grupo no WhatsApp, guardado para o n8n não procurar de novo. */
    public static function set_members(int $group_id, int $members, string $jid = ''): bool
    {
        global $wpdb;
        if (!$wpdb->get_var($wpdb->prepare('SELECT id FROM ' . IWAB_DB::t('groups') . ' WHERE id = %d', $group_id))) {
            return false;
        }
        $data = ['members' => max(0, $members), 'members_at' => gmdate('Y-m-d H:i:s')];
        if ($jid !== '') {
            $data['jid'] = $jid;
        }
        $wpdb->update(IWAB_DB::t('groups'), $data, ['id' => $group_id]);
        return true;
    }

    public static function delete(int $id): void
    {
        global $wpdb;
        $wpdb->delete(IWAB_DB::t('people'), ['test_id' => $id]);
        $wpdb->delete(IWAB_DB::t('groups'), ['test_id' => $id]);
        $wpdb->delete(IWAB_DB::t('versions'), ['test_id' => $id]);
        $wpdb->delete(IWAB_DB::t('tests'), ['id' => $id]);
    }

    /** Zera os resultados: todos os IPs voltam a ser novos e são redistribuídos no próximo clique. */
    public static function reset(int $id): void
    {
        global $wpdb;
        $wpdb->delete(IWAB_DB::t('people'), ['test_id' => $id]);
    }
}
