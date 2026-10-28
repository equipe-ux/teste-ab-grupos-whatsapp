<?php
/**
 * Tabelas próprias (prefixo iwab_).
 *
 * tests    — um teste = um link (/grupo/{slug}).
 * versions — as versões do teste (A, B, C…) com o % de tráfego.
 * groups   — a fila de grupos de cada versão (SendFlow ou convite do WhatsApp). Encheu um, entra o próximo.
 *            members/members_at = contagem real de membros mandada pelo n8n (WAHA); NULL = nunca informado.
 * people   — um registro por IP em cada teste, com a versão e o grupo dele. É o que garante "mesmo IP, mesmo grupo".
 */

if (!defined('ABSPATH')) {
    exit;
}

class IWAB_DB
{
    public static function t(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . 'iwab_' . $name;
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        dbDelta([
            'CREATE TABLE ' . self::t('tests') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL DEFAULT '',
  slug varchar(100) NOT NULL DEFAULT '',
  full_at int(10) unsigned NOT NULL DEFAULT 1000,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug)
) $charset;",
            'CREATE TABLE ' . self::t('versions') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  test_id bigint(20) unsigned NOT NULL,
  name varchar(190) NOT NULL DEFAULT '',
  weight int(10) unsigned NOT NULL DEFAULT 50,
  position int(10) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY test_id (test_id)
) $charset;",
            'CREATE TABLE ' . self::t('groups') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  test_id bigint(20) unsigned NOT NULL,
  version_id bigint(20) unsigned NOT NULL,
  url varchar(2000) NOT NULL DEFAULT '',
  max_people int(10) unsigned NOT NULL DEFAULT 0,
  jid varchar(100) NOT NULL DEFAULT '',
  members int(10) unsigned DEFAULT NULL,
  members_at datetime DEFAULT NULL,
  position int(10) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY test_id (test_id),
  KEY version_id (version_id)
) $charset;",
            'CREATE TABLE ' . self::t('people') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  test_id bigint(20) unsigned NOT NULL,
  ip_hash char(64) NOT NULL,
  version_id bigint(20) unsigned NOT NULL,
  group_id bigint(20) unsigned NOT NULL DEFAULT 0,
  clicks int(10) unsigned NOT NULL DEFAULT 1,
  first_at datetime NOT NULL,
  last_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY test_ip (test_id,ip_hash),
  KEY test_version (test_id,version_id),
  KEY test_group (test_id,group_id)
) $charset;",
        ]);

        self::migrate_010();
        update_option('iwab_db_version', IWAB_VERSION);
    }

    /** Atualização pelo zip não chama a ativação: a troca de versão é que roda o dbDelta. */
    public static function maybe_upgrade(): void
    {
        if (get_option('iwab_db_version') !== IWAB_VERSION) {
            self::install();
        }
    }

    /** Na 0.1.0 cada versão tinha um link só (versions.url). Ele vira o primeiro grupo da fila da versão. */
    private static function migrate_010(): void
    {
        global $wpdb;
        $versions = self::t('versions');
        $groups = self::t('groups');
        if (!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $versions LIKE %s", 'url'))) {
            return;
        }
        $rows = $wpdb->get_results(
            "SELECT v.id, v.test_id, v.url FROM $versions v LEFT JOIN $groups g ON g.version_id = v.id WHERE v.url <> '' AND g.id IS NULL"
        );
        foreach ($rows as $row) {
            $wpdb->insert($groups, [
                'test_id'    => (int) $row->test_id,
                'version_id' => (int) $row->id,
                'url'        => $row->url,
                'position'   => 0,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $wpdb->query($wpdb->prepare(
                'UPDATE ' . self::t('people') . ' SET group_id = %d WHERE version_id = %d AND group_id = 0',
                (int) $wpdb->insert_id,
                (int) $row->id
            ));
        }
    }
}
