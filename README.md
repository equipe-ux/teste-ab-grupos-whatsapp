# Teste A/B de grupos de WhatsApp (WordPress + n8n + WAHA)

Plugin WordPress que coloca **um link só** na frente de vários grupos de WhatsApp: divide as pessoas entre versões
(teste A/B), mantém cada pessoa sempre no mesmo grupo e tira da fila os grupos que encheram. Um fluxo do n8n,
usando o WAHA, conta os membros reais de cada grupo e avisa o plugin.

> Este repositório é um **template**: plugin, fluxo do n8n e guia de montagem. Ele é entregue como está, sem suporte e
> sem atualizações. Nenhum endereço, chave ou número vem preenchido: você usa o seu site, o seu n8n e o seu WAHA.

**Download do plugin:** [inovaweb-ab-whatsapp.zip](../../releases/latest/download/inovaweb-ab-whatsapp.zip)
(ou na aba **Releases**). Fluxo do n8n: [`n8n/fluxo-contagem-membros.json`](n8n/fluxo-contagem-membros.json).

---

## Sumário

1. [Por que existe](#1-por-que-existe)
2. [Como as peças se ligam](#2-como-as-peças-se-ligam)
3. [O que você precisa](#3-o-que-você-precisa)
4. [Instalar o plugin](#4-instalar-o-plugin)
5. [Criar um teste: links e divisão de tráfego](#5-criar-um-teste-links-e-divisão-de-tráfego)
6. [Como o plugin escolhe o grupo](#6-como-o-plugin-escolhe-o-grupo)
7. [Quando um grupo é considerado cheio](#7-quando-um-grupo-é-considerado-cheio)
8. [Preparar o WAHA](#8-preparar-o-waha)
9. [Ligar o n8n no plugin e no WAHA](#9-ligar-o-n8n-no-plugin-e-no-waha)
10. [Checklist de lançamento](#10-checklist-de-lançamento)
11. [Problemas comuns](#11-problemas-comuns)
12. [Pontos de atenção](#12-pontos-de-atenção)
13. [Referência da API do plugin](#13-referência-da-api-do-plugin)

---

## 1. Por que existe

Quando o link de entrada manda para todos os grupos, quem quer aplicar golpe clica várias vezes e entra em todos.
O plugin resolve isso:

- **Um link só por teste** (ex.: `https://seu-site.com.br/grupo/lancamento`). É ele que vai no botão da página e nos anúncios.
- **Mesmo IP, mesmo grupo, sempre.** Clicar de novo ou limpar o navegador não muda o grupo.
- **Os links dos grupos nunca aparecem no site.** Pré-visualização de link (WhatsApp, Facebook…), robôs e scripts recebem
  uma página neutra, sem link de grupo.
- **Teste A/B:** o tráfego é dividido entre versões (A, B, C…) na porcentagem que você definir.
- **Grupo cheio sai da fila sozinho:** pela contagem do próprio plugin e pela contagem real de membros que o n8n busca no WAHA.

## 2. Como as peças se ligam

```
Visitante ──► seu-site.com.br/grupo/{teste} ──► plugin escolhe a versão e o grupo ──► chat.whatsapp.com/…

n8n (a cada 10 min)
  1. GET  seu-site/wp-json/iwab/v1/groups         (lista de grupos do plugin)
  2. GET  seu-waha/api/{sessão}/groups/…          (acha o grupo e conta os membros)
  3. POST seu-site/wp-json/iwab/v1/groups/{id}    (manda só o número de membros)
```

O plugin **nunca chama** o n8n nem o WAHA: quem pergunta e manda os números é o n8n. A contagem real é opcional:
sem n8n/WAHA o plugin funciona só com o limite de envios (item 7).

## 3. O que você precisa

| Peça | Para quê | Obrigatório? |
|---|---|---|
| WordPress 5.9+ com PHP 7.4+ | Roda o plugin e o link do teste | Sim |
| Grupos de WhatsApp com link de convite (`https://chat.whatsapp.com/…`) ou links do SendFlow | Destino das pessoas | Sim |
| [WAHA](https://waha.devlike.pro) 2026.6.1 ou mais novo | Ler quantos membros cada grupo tem | Para a contagem real |
| [n8n](https://n8n.io) | Buscar a contagem no WAHA e mandar para o plugin | Para a contagem real |
| Um número de WhatsApp conectado ao WAHA, dentro de todos os grupos | É por ele que o WAHA enxerga os grupos | Para a contagem real |

## 4. Instalar o plugin

1. Baixe o [`inovaweb-ab-whatsapp.zip`](../../releases/latest/download/inovaweb-ab-whatsapp.zip).
2. No WordPress: **Plugins → Adicionar novo → Enviar plugin** → escolha o zip → **Instalar agora** → **Ativar**.
3. Vai aparecer o menu **Teste A/B WhatsApp** no painel.
4. Para atualizar: mesmo caminho e, na tela seguinte, **Substituir atual pelo enviado**. Testes, grupos e resultados são mantidos.

> **Atenção:** *Excluir* o plugin (Plugins → Excluir) apaga as tabelas e a chave da integração. *Desativar* não apaga nada.

Se o site usa links permanentes "simples", o link do teste fica `https://seu-site.com.br/?iwab={teste}`.

## 5. Criar um teste: links e divisão de tráfego

1. **Teste A/B WhatsApp → Novo teste.**
2. **Nome do teste:** só aparece no painel (ex.: "Lançamento Março").
3. **Link:** o final do link (ex.: `lancamento` → `seu-site.com.br/grupo/lancamento`). Vazio = usa o nome do teste.
4. **Grupo cheio com:** quantos membros reais contam como grupo cheio. Padrão **1.000** (o WhatsApp aceita até 1.024).
5. **Versões** — para cada versão (A, B, C…):
   - **Nome** (opcional), ex.: "GRUPO 1".
   - **Tráfego:** a divisão é **proporcional**. Exemplos:

     | Versões | Tráfego digitado | Resultado |
     |---|---|---|
     | A, B | 50 e 50 | 50% / 50% |
     | A, B | 70 e 30 | 70% / 30% |
     | A, B, C, D, E | 20 em cada | 20% cada |
     | A, B, C | 1, 1, 2 | 25% / 25% / 50% |
     | qualquer | 0 | **pausa** a versão |

     O painel mostra o % efetivo de cada versão ao lado do campo.
   - **Grupos:** a fila da versão, em ordem. Cole o convite `https://chat.whatsapp.com/…` (ou um link do SendFlow).
     Use **+ Adicionar grupo** para deixar grupos de reserva (A2, A3…): quando o primeiro enche, o próximo assume.
   - **Limite de envios** (opcional): ver o item 7.
6. **Criar teste.** O link aparece no topo da tela, com o botão **Copiar**.
7. Se for usar a contagem real: coloque o **número do WAHA dentro de todos os grupos** com convite (item 8).
8. Teste o link no celular. **Isso conta como uma pessoa** e fixa o seu IP num grupo.
9. **Zerar resultados** (fim da tela do teste) antes de soltar o link nos anúncios. Não mexe na contagem de membros.
10. Coloque o link do teste no botão da página / anúncio. **Nunca** os links dos grupos.

## 6. Como o plugin escolhe o grupo

| Situação | O que acontece |
|---|---|
| Pessoa nova | Ganha a versão que está mais "atrasada" em relação à porcentagem dela, e o **primeiro grupo com vaga** na fila dessa versão. |
| Mesma pessoa (mesmo IP) clicando de novo | **Sempre o mesmo grupo**, mesmo que ele tenha enchido depois (ela provavelmente já está lá dentro). |
| O grupo dela foi removido do teste | Vai para o grupo com vaga **da mesma versão**. |
| A versão dela foi pausada (0%) | Ganha outra versão. |
| Uma versão ficou sem nenhum grupo com vaga | Sai da divisão sozinha; as outras dividem o tráfego (ex.: 5 × 20% → 4 × 25%). |
| Todas as versões sem vaga | O link mostra "Os grupos estão cheios no momento". |

**Compensação:** o plugin tenta deixar o total de pessoas de cada versão igual ao planejado **desde o começo do teste**.
Se uma versão ficar fechada um tempo e depois ganhar grupo novo, ela recebe **todo mundo novo** até alcançar as outras.
Para evitar isso, mantenha sempre um grupo de reserva na fila de cada versão.

Com versões de tráfego igual e um grupo cada, os grupos enchem **mais ou menos ao mesmo tempo**: cadastre as reservas
antes de chegar em ~900 membros.

## 7. Quando um grupo é considerado cheio

Vale o que bater primeiro:

1. **Limite de envios** (campo por grupo): quantas pessoas (IPs) o plugin manda para o grupo. É uma estimativa: nem todo
   mundo que clica entra, então o grupo fica com **menos** gente que esse número.
   - **Com o n8n ligado:** pode deixar vazio, ou usar um número alto (1.300–1.500) como rede de segurança para picos entre uma
     checagem e outra.
   - **Sem o n8n:** coloque ~1.000.
   - **Link do SendFlow:** deixe vazio; o SendFlow cuida da lotação dele.
2. **Membros reais** (vindo do n8n): chegou no "Grupo cheio com" do teste, o grupo para de receber gente nova.

**Situação de cada grupo no painel (Resultados):**

| Selo | Significado |
|---|---|
| Recebendo | É o grupo que está recebendo as pessoas novas dessa versão. |
| Enchendo | Passou de 90% do limite. Hora de garantir grupo de reserva. |
| Cheio | Não recebe mais ninguém novo (quem já era dele continua indo). |
| Na fila | Grupo de reserva: entra quando o de cima encher. |
| Pausada | Versão com 0% de tráfego. |

Colunas: **Enviadas** = IPs mandados para o grupo · **Membros** = contagem real do n8n e há quanto tempo ·
**Cliques** = todos os acessos, com repetições.

## 8. Preparar o WAHA

O WAHA conecta um número de WhatsApp (como o WhatsApp Web) e deixa o n8n consultar os grupos. Desde a versão 2026.6.1
o WAHA gratuito tem várias sessões e todas as funções.

**Recomendado: uma sessão só para isso**, separada de qualquer outro robô que você já tenha no WAHA.

1. No painel do WAHA: **Sessions → Start New**.
2. Preencha:

   | Campo | Valor | Por quê |
   |---|---|---|
   | Name | ex.: `grupos` | Vai no `waha_session` do n8n. Não dá para renomear depois. |
   | Engine | NOWEB (testado) | Outros motores (WEBJS/GOWS) podem mudar o formato da resposta: ver item 11. |
   | Presence | **offline** | Com "online" o celular do número pode parar de receber notificações. |
   | Store | **Enabled** | As consultas de grupo funcionam melhor com ele (só dá para escolher na criação). |
   | Store: Full Sync | **Off** | Não precisa baixar o histórico de conversas. |
   | Webhooks | **nenhum** | O n8n é quem pergunta ao WAHA. Sem webhook, nenhuma mensagem dos grupos sai daqui. |

3. **Create & Start** → abra o QR code (ícone de câmera) → no celular: WhatsApp → **Aparelhos conectados → Conectar um
   aparelho**. Espere o status **WORKING**.
4. Coloque esse número em **todos** os grupos com convite cadastrados no plugin (de preferência como admin).
5. Anote três coisas para o n8n: o **endereço do WAHA**, o **nome da sessão** e a **chave da API** (`WAHA_API_KEY`).

> Se o WAHA tiver webhook global configurado por variável de ambiente (`WHATSAPP_HOOK_URL`), ele vale para todas as
> sessões, inclusive esta. Confira antes, se você tem outros robôs ligados no mesmo WAHA.

## 9. Ligar o n8n no plugin e no WAHA

### 9.1 Importar o fluxo

1. No n8n: **Create workflow → ⋯ → Import from File** → [`n8n/fluxo-contagem-membros.json`](n8n/fluxo-contagem-membros.json).
2. O fluxo vem sem credenciais e com endereços de exemplo. Os próximos passos preenchem tudo.

### 9.2 Credencial do plugin (n8n → plugin)

1. No WordPress: **Teste A/B WhatsApp** → fim da página → **Copiar** a chave da integração.
2. No n8n: **Credentials → Add credential → Header Auth**:
   - Nome da credencial: ex.: `Plugin Teste A/B`
   - **Name:** `X-IWAB-Key`
   - **Value:** a chave copiada
3. Selecione essa credencial nos nós **Plugin: listar grupos** e **Plugin: enviar membros**.

### 9.3 Credencial do WAHA (n8n → WAHA)

1. No n8n: **Credentials → Add credential → Header Auth**:
   - Nome da credencial: ex.: `WAHA`
   - **Name:** `X-Api-Key`
   - **Value:** a chave da API do seu WAHA
2. Selecione essa credencial nos nós **WAHA: achar grupo pelo convite** e **WAHA: membros do grupo**.

A chave da API vale para o WAHA inteiro (todas as sessões). Qual sessão é usada se escolhe no passo seguinte.

### 9.4 Nó "Configuração"

| Campo | O que colocar | Exemplo |
|---|---|---|
| `site_url` | Endereço do site com o plugin | `https://seu-site.com.br` |
| `waha_url` | Endereço do WAHA, sem barra no fim | `https://seu-waha.com.br` |
| `waha_session` | Nome da sessão criada no item 8 | `grupos` |

### 9.5 Testar e publicar

1. Clique em **Execute workflow**. Todos os nós devem ficar verdes e **Plugin: enviar membros** deve sair com um item por grupo.
2. No WordPress, coluna **Membros**: deve mostrar o número de cada grupo e "há poucos segundos".
3. Nas configurações do fluxo (**⋯ → Settings**), deixe **Save successful production executions: Do not save** — assim só
   os erros ficam salvos e o banco do n8n não enche.
4. **Publish / Active.** A partir daí o fluxo roda a cada 10 minutos.

### O que o fluxo faz

| Nó | O que faz |
|---|---|
| A cada 10 minutos | Agendamento. |
| Configuração | `site_url`, `waha_url`, `waha_session`. |
| Plugin: listar grupos | `GET {site_url}/wp-json/iwab/v1/groups` (só convites do WhatsApp; links do SendFlow ficam de fora). |
| Separar grupos | Um item por grupo. |
| Já tem o id do grupo? | Se o plugin já guardou o `jid` do grupo, pula a busca pelo convite. |
| WAHA: achar grupo pelo convite | `GET {waha_url}/api/{waha_session}/groups/join-info?code={convite}`. |
| Grupo + id | Junta o grupo do plugin com o `jid` (`…@g.us`). |
| WAHA: membros do grupo | `GET {waha_url}/api/{waha_session}/groups/{jid}/participants`. |
| Contar membros | Conta a lista. **Os telefones não saem do n8n.** |
| Contou? | Só segue se o WAHA respondeu 200. |
| Plugin: enviar membros | `POST {site_url}/wp-json/iwab/v1/groups/{id}` com `{"members": …, "jid": "…"}`. |

### Trocar a chave da integração

1. No plugin: fim da página → **Gerar nova chave** → **Copiar**.
2. No n8n: credencial do plugin → cole no **Value** → **Save**.

A chave antiga para de funcionar na hora; até trocar no n8n, a contagem para (o limite de envios continua valendo).

### Vários sites

Duplique o fluxo no n8n, troque o `site_url` e crie outra credencial Header Auth com a chave do plugin desse site.

## 10. Checklist de lançamento

**Antes**
- [ ] Teste criado com todas as versões e **grupos de reserva** na fila de cada uma.
- [ ] Número do WAHA dentro de **todos** os grupos com convite.
- [ ] Coluna **Membros** atualizando ("há X minutos", no máximo ~10).
- [ ] Link testado no celular → **Zerar resultados**.
- [ ] Link do teste no botão da página / anúncios (nunca os links dos grupos).

**Durante**
- [ ] Acompanhar os selos **Enchendo** / **Cheio** e acrescentar grupos na fila se preciso.
- [ ] Olhar *Executions* do fluxo no n8n: só aparecem erros.
- [ ] Sessão do WAHA em **WORKING**.

## 11. Problemas comuns

| Sintoma | Causa provável | O que fazer |
|---|---|---|
| Todo mundo caindo no mesmo grupo | O plugin está vendo o IP do servidor/proxy, não o do visitante | Na tela do plugin, caixa **IP detectado**: tem que ser o seu IP real. Se não for, acrescente `define('IWAB_TRUST_FORWARDED', true);` no `wp-config.php` (o Cloudflare já é reconhecido sozinho). |
| Coluna Membros com "—" | O n8n ainda não contou esse grupo | Espere até 10 min. Se continuar: o número do WAHA não está no grupo, ou o convite é inválido/foi trocado. |
| Membros de um grupo parou de atualizar e os outros não | O número do WAHA saiu (ou nunca entrou) nesse grupo | Coloque o número de volta no grupo. O fluxo não acusa erro nesse caso: só deixa de atualizar. |
| Membros "há muitas horas" em todos | O fluxo parou ou o WAHA desconectou | Veja *Executions* no n8n, se o fluxo está ativo e se a sessão está WORKING (se o número foi desconectado no celular, escaneie o QR de novo). |
| Erro 401 no n8n | Chave do plugin trocada | Atualize a credencial (item 9). |
| Erro em *Grupo + id* ou contagem vazia | Motor do WAHA diferente do NOWEB muda o formato da resposta | Ajuste o código do nó *Grupo + id* / *Contar membros* para o formato do seu motor. |
| Trocou o link de um grupo e a contagem sumiu | Normal: a contagem era do grupo antigo | O n8n acha o grupo novo na próxima rodada. |
| Link mostra "Abra este link no navegador do celular" | Acesso identificado como robô/prévia | Normal para prévias de link. Pessoas reais não veem isso. |
| Link mostra "Os grupos estão cheios" | Nenhuma versão tem grupo com vaga | Acrescente grupos na fila das versões. |
| Link dá página não encontrada | Final do link errado ou teste excluído | Confira o link no painel. Com links permanentes "simples", o link é `/?iwab={teste}` (o painel já mostra o certo). |

## 12. Pontos de atenção

- **IP compartilhado:** pessoas no mesmo Wi-Fi ou na mesma operadora com IP compartilhado (CGNAT) caem no mesmo grupo.
  IPv6 conta pelo prefixo /64.
- **Privacidade:** o plugin guarda só um código (hash) do IP, nunca o IP em si; o n8n manda só a contagem de membros.
- **Admins contam como membros:** a contagem inclui o número do WAHA e os admins do grupo.
- **Chave da integração:** fica só no painel do plugin e na credencial do n8n. Não cole em mensagens.
- **Cache:** o link do teste responde sem cache (`no-store`, `DONOTCACHEPAGE`, LiteSpeed). Não precisa configurar nada.
- **Número do WAHA:** é um aparelho conectado ao WhatsApp do número. Se ele for desconectado no celular, a contagem para.

## 13. Referência da API do plugin

Cabeçalho em todas as chamadas: `X-IWAB-Key: {chave}` (ou `Authorization: Bearer {chave}`).

**Listar grupos** — `GET https://seu-site.com.br/wp-json/iwab/v1/groups`

```json
{"groups":[{"id":1,"test":"Lançamento","group":"A1","invite_code":"…","jid":"120363…@g.us",
  "members":3,"members_at":"2026-01-01T12:00:00+00:00","sent":1,"full":false}]}
```

**Enviar membros** — `POST https://seu-site.com.br/wp-json/iwab/v1/groups/{id}`

```json
{"members": 987, "jid": "120363…@g.us"}
```

Respostas: `401` chave inválida · `400` número de membros inválido · `404` grupo não existe.

Detalhes do código (tabelas, arquivos, regras de IP): [`plugin/inovaweb-ab-whatsapp/README.md`](plugin/inovaweb-ab-whatsapp/README.md).

---

## Estrutura do repositório

| Caminho | O quê |
|---|---|
| `plugin/inovaweb-ab-whatsapp/` | Código do plugin (PHP, um JS e um CSS, sem build). |
| `n8n/fluxo-contagem-membros.json` | Fluxo do n8n para importar (sem credenciais, endereços de exemplo). |
| Releases → `inovaweb-ab-whatsapp.zip` | Plugin pronto para enviar no WordPress. |

## Licença e crédito

Plugin desenvolvido por [Inova Web Design](https://inovawebdesign.com.br). Licença **GPLv2 ou posterior** (ver [`LICENSE`](LICENSE)).
Fornecido como está, sem garantia, suporte ou atualizações.
