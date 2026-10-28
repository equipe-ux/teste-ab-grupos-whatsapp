# INOVAWEB Teste A/B WhatsApp

Plugin WordPress para **teste A/B de grupos de WhatsApp** com um link só. Tabelas próprias (`wp_iwab_*`), sem cache, sem funil.

O passo a passo completo (montar, dividir tráfego, ligar no n8n e no WAHA) está no README da raiz do repositório.

## Como funciona

- Cada **teste** tem um link: `https://site.com/grupo/{slug}` (ou `https://site.com/?iwab={slug}` se o site usa links permanentes simples).
- O teste tem **versões** (A, B, C…). **Tráfego** proporcional
  (50/50, 70/30…); **0% pausa** a versão.
- Cada versão tem uma **fila de grupos** (A1, A2, A3…): link do SendFlow ou convite `https://chat.whatsapp.com/…`.
  Todo mundo da versão vai para o primeiro grupo com vaga; encheu, passa para o próximo. Sem vaga em nenhum grupo da versão,
  a gente nova vai para outra versão; sem vaga em nenhuma, o link mostra "os grupos estão cheios".
- **Fixo por IP**: o mesmo IP cai sempre no mesmo grupo, mesmo que o grupo tenha enchido depois (a pessoa provavelmente já está nele).
  Só muda se o grupo for removido (vai para o grupo com vaga da mesma versão) ou a versão for pausada (ganha outra versão).
- Os links dos grupos **nunca aparecem no site**. Pré-visualizações de link (WhatsApp, Facebook…), robôs e scripts recebem uma
  página neutra, sem link, e não entram na conta.

## Quando um grupo está cheio

Duas regras, vale a que bater primeiro:

1. **Limite de envios** (campo por grupo, opcional): o plugin conta quantos IPs mandou para o grupo. É uma estimativa que erra
   para o lado seguro: nem todo mundo que clica entra, então o grupo fica com menos gente que o limite.
2. **Contagem real de membros** (n8n + WAHA): o n8n consulta os grupos no WAHA e manda o número de membros para o plugin.
   Chegou no "Grupo cheio com N membros" do teste (padrão 1.000; o WhatsApp aceita 1.024), o grupo para de receber gente nova.

Links do SendFlow não entram na contagem do n8n: o SendFlow cuida da lotação dos grupos dele.

## API para o n8n

Endereços e chave ficam na tela do plugin (Teste A/B WhatsApp → fim da página).

- `GET /wp-json/iwab/v1/groups` — convites de todos os testes:
  `{"groups":[{"id":3,"test":"…","group":"B1","invite_code":"…","jid":"…@g.us","members":987,"members_at":"…","sent":1200,"full":false}]}`
- `POST /wp-json/iwab/v1/groups/{id}` com `{"members": 987, "jid": "120363…@g.us"}` — grava a contagem real.
  O `jid` (id do grupo no WhatsApp) é guardado para o n8n não precisar procurar o grupo pelo convite de novo.
- Autenticação: cabeçalho `X-IWAB-Key: {chave}` (ou `Authorization: Bearer {chave}`). "Gerar nova chave" invalida a anterior.

O plugin não chama o WAHA nem servidor nenhum: quem busca e manda os números é o n8n.

## Detalhes que importam

- **IP**: o plugin guarda só um hash do IP (HMAC com o salt do site). IPv6 conta pelo prefixo /64, porque o celular troca o fim do
  endereço sozinho. Pessoas atrás do mesmo IP (Wi-Fi de empresa, operadora com CGNAT) caem no mesmo grupo.
- **Proxy/CDN**: Cloudflare é reconhecido sozinho. `X-Forwarded-For` só vale quando a conexão vem de IP interno (proxy da própria
  hospedagem). A tela do plugin mostra o IP detectado do administrador: se não for o IP real dele, todo mundo cairia no mesmo grupo.
  Hospedagem com proxy de IP público: `define('IWAB_TRUST_FORWARDED', true);` no `wp-config.php`.
- **Cache**: o link responde com `no-store`, `DONOTCACHEPAGE` e `X-LiteSpeed-Cache-Control: no-cache`. Não precisa configurar nada.
- Testar o próprio link conta como uma pessoa. Antes de publicar, use **Zerar resultados**.
- Excluir o plugin (Plugins → Excluir) apaga as tabelas `wp_iwab_*` e a chave. Desativar não apaga nada.

## Requisitos

WordPress 5.9+ e PHP 7.4+. Sem dependências externas e sem build: PHP, um JS e um CSS servidos como estão.

## Arquivos

| Arquivo | Papel |
|---|---|
| `inovaweb-ab-whatsapp.php` | Cabeçalho, constantes `IWAB_*`, hooks. |
| `includes/class-iwab-db.php` | Tabelas `tests`, `versions`, `groups`, `people` (dbDelta na troca de versão) e migração da 0.1.0. |
| `includes/class-iwab-ip.php` | IP do visitante (Cloudflare/proxy), hash, robôs. |
| `includes/class-iwab-tests.php` | CRUD, fila de grupos, escolha do grupo de cada IP, regra de grupo cheio, dados para a API. |
| `includes/class-iwab-router.php` | O link `/grupo/{slug}`: roda em `plugins_loaded`, antes do tema. |
| `includes/class-iwab-api.php` | API REST para o n8n (chave em `iwab_api_key`). |
| `includes/class-iwab-admin.php` | Painel (lista, editor, resultados, chave da integração). |
| `assets/admin.js`, `assets/admin.css` | Versões e grupos no editor, % efetivo, copiar. |
| `uninstall.php` | Apaga as tabelas e a chave ao excluir o plugin. |
