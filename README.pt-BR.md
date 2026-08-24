# ClickTrail

[![WordPress tested](https://img.shields.io/badge/WordPress-v7.0%20tested-3858e9.svg)](https://wordpress.org)

![ClickTrail](.github/clicktrail-cover.png)

> **Status de verificacao das integracoes (2026-08-19):** o registry e o codigo comprovam wiring, nao suporte
> de producao nos provedores. Os testes E2E de PHP/WordPress/provedores nao estavam disponiveis nesta auditoria.
> Os adaptadores server-side com nome de plataforma estao **presentes no codigo / runtime nao verificado** e
> enviam JSON para endpoint configurado. O GTM pode mediar tags do site; o ClickTrail nao injeta SDKs de Meta/
> Facebook Pixel, Google tag, TikTok Pixel, LinkedIn Insight, Pinterest Tag ou Reddit Pixel. Reddit possui apenas
> um destino **relay-only** e captura de `rdt_cid`, nao um adaptador nativo. Veja a [referencia de integracoes](docs/reference/INTEGRATIONS.md)
> e o [ledger de evidencias](docs/reference/integration-capabilities.json).

A atribuicao costuma quebrar em algum ponto entre o clique no anuncio e a conversao. O ClickTrail mantem o contexto da campanha vivo ao longo da jornada ate a conversao no WordPress.

ClickTrail e um plugin de atribuicao para WordPress feito para sites que precisam manter os dados de origem da campanha disponiveis ao longo da jornada, especialmente quando pedidos do WooCommerce ou formularios acontecem varias paginas depois da landing page.

**O que o ClickTrail nao e:** nao e um painel de atribuicao, uma plataforma hospedada de server-side GTM, um gestor de leads nem um otimizador de anuncios. Ele complementa GA4 e GTM. As tags do navegador continuam sob controle do site, enquanto os adaptadores server-side para endpoints configurados permanecem presentes no codigo e sem verificacao de runtime nesta baseline.

Ele foi pensado para os problemas que normalmente quebram a atribuicao em producao:

- paginas com cache
- formularios dinamicos ou carregados via AJAX
- jornadas com varias paginas ou varias sessoes
- fluxos entre dominios
- necessidade de tracking com controles de consentimento e limites documentados
- entrega opcional server-side, sujeita a verificacao de runtime

Em vez de capturar uma UTM uma vez e torcer para que ela sobreviva, o ClickTrail mantem o contexto de primeiro toque e ultimo toque disponivel ate o momento em que pedidos do WooCommerce, formularios, eventos no navegador ou fluxos de entrega realmente precisam dele.

O ClickTrail guarda a origem da visita, nao um perfil do visitante. A captura e first-party e possui controles de consentimento; por padrao, o plugin nao chama servicos externos para identificar ou enriquecer visitantes, e os dados saem apenas por integracoes ativadas. Consulte os bloqueios atuais de seguranca antes de tratar qualquer caminho como completo em privacidade.

## O Que o ClickTrail Faz

O ClickTrail captura atribuicao de primeiro toque e ultimo toque, mantem esses dados disponiveis durante a jornada do visitante e faz com que essa informacao chegue ao ponto em que a conversao realmente acontece dentro do WordPress.

Ele combina:

- captura de atribuicao
- atribuicao em pedidos do WooCommerce com payload de compra enriquecido
- enriquecimento de formularios
- coleta de eventos no navegador
- controles de consentimento com limites de verificacao documentados
- transporte server-side opcional com fila e diagnosticos

Isso permite comecar por pedidos do WooCommerce com atribuicao de campanha ou por formularios, e adicionar eventos no navegador, integracoes de consentimento ou entrega server-side depois, quando a operacao realmente precisar.

## Problemas Que Ele Resolve

### 1. Perda de atribuicao dentro do WordPress

O usuario entra com UTMs ou click IDs, navega algumas paginas e converte depois. Outros visitantes chegam por busca organica ou redes sociais sem tags. Sem persistencia, o formulario ou pedido perde a origem original.

O ClickTrail mantem a trilha da origem disponivel em formularios, checkout e payloads de evento.

### 2. Cache e formularios dinamicos quebrando campos ocultos

Muitos plugins de atribuicao dependem apenas de campos hidden renderizados no servidor. Isso falha quando a pagina esta em cache ou quando o formulario entra depois do carregamento.

O ClickTrail inclui fallback client-side e observacao de conteudo dinamico para continuar levando a atribuicao aos formularios configurados e aos campos hidden compativeis.

### 3. Pedidos do WooCommerce sem origem de campanha

Trafego pago frequentemente acaba aparecendo como direto dentro dos pedidos.

O ClickTrail grava a atribuicao no pedido, envia um evento de compra enriquecido para o `dataLayer` e pode estender a mesma jornada Woo para `view_item`, `view_item_list`, `view_cart`, `add_to_cart`, `remove_from_cart`, `begin_checkout`, `user_data` opcional no `dataLayer`, marcos pos-compra e dispatch server-side opcional.

### 4. Jornadas entre dominios perdendo continuidade

Quando o usuario sai do site principal para app, agenda, checkout ou outro subdominio, a atribuicao costuma reiniciar.

O ClickTrail oferece decoracao de links entre dominios permitidos e continuidade via token de atribuicao.

### 5. Consentimento e entrega separados em ferramentas diferentes

Muitas equipes precisam que privacidade, captura de evento e entrega conversem entre si.

O ClickTrail junta consentimento, intake de eventos e configuracao de entrega no mesmo plugin.

## Capacidades Principais

### Capture

- UTMs de primeiro toque e ultimo toque, incluindo `utm_id`, `utm_source_platform`, `utm_creative_format` e `utm_marketing_tactic`
- captura de referrer com inferencia automatica de origem organica, social ou referral quando nao existem UTMs
- captura dos principais click IDs de anuncios e identificadores first-party de browser/plataforma
- retencao configuravel da atribuicao
- decoracao de links cross-domain
- continuidade opcional com token de atribuicao

Click IDs suportados:

- `gclid`
- `wbraid`
- `gbraid`
- `fbclid`
- `ttclid`
- `msclkid`
- `twclid`
- `li_fat_id`
- `sccid`
- `epik`

Identificadores adicionais de browser incluem:

- `fbc`
- `fbp`
- `_ttp`
- `li_gc`
- `ga_client_id`
- `ga_session_id`

### Forms

O ClickTrail se conecta a formularios por tres padroes documentados. Confirme qual padrao se aplica antes de testar:

1. **Campos hidden automaticos** — Contact Form 7 e Fluent Forms recebem os campos de atribuicao pelo caminho documentado.
2. **Campos hidden correspondentes** — Gravity Forms e WPForms preenchem os campos `ct_*` que voce adiciona ao formulario.
3. **Armazenamento na submissao** — Elementor Forms (Pro) e Ninja Forms anexam a atribuicao ao registro da submissao em vez de injetar campos hidden.

- enriquecimento automatico de campos hidden no Contact Form 7 e no Fluent Forms
- preenchimento compativel de campos hidden ja existentes no Gravity Forms e no WPForms
- recomendado para Gravity Forms e WPForms: adicione os campos hidden que deseja armazenar ou exportar, e o ClickTrail faz o preenchimento
- fallback client-side para paginas com cache
- deteccao de formularios dinamicos
- opcao para substituir valores de atribuicao ja existentes
- suporte para append de atribuicao no WhatsApp
- intake de webhooks de fontes externas documentadas; runtime ainda nao verificado

### Events

- coleta de eventos no navegador
- pushes para `dataLayer` em formato amigavel para GA4
- eventos de busca, download, scroll, tempo na pagina, interacoes de lead gen e eventos pontuais do WordPress como `login`, `sign_up` e `comment_submit`
- `view_item_list` opcional do Woo com contexto de `item_list_name` e `item_list_index`
- contrato enriquecido opcional de `dataLayer` do Woo com `user_data` sensivel a consentimento
- intake de atualizacoes de lifecycle para CRM ou backend
- pipeline canonico unificado por tras da interface

### Delivery

- transporte server-side opcional
- fila de retry com backoff
- diagnosticos de entrega e telemetria de falha
- bloqueio por consentimento quando necessario
- visao de backlog da fila e teste de endpoint

## Inventario de integracoes e status

### WordPress e frontend

- WordPress 6.5+
- PHP 8.1+
- banner de consentimento proprio quando o plugin e a fonte de consentimento
- injecao opcional de container do GTM
- modo de compatibilidade sGTM com URL do tagging server, entrega first-party do script e suporte a custom loader

### Formularios (conectores de origem; runtime nao verificado nesta auditoria)

- Contact Form 7
- Elementor Forms (Pro)
- Fluent Forms
- Gravity Forms
- Ninja Forms
- WPForms

Comportamento por plugin:

- Contact Form 7 e Fluent Forms podem receber campos hidden de atribuicao automaticamente
- Gravity Forms e WPForms podem preencher campos hidden compativeis que voce adiciona ao formulario
- Elementor Forms (Pro) usam hooks de submissao e fallback de atribuicao, nao injecao automatica de campos hidden
- Ninja Forms grava a atribuicao junto da submissao e mostra esses dados no detalhe do registro, em vez de injecao automatica de campos hidden

### Comercio (origem; runtime nao verificado nesta auditoria)

- atribuicao em pedidos do WooCommerce
- push enriquecido do evento de compra para o `dataLayer`
- eventos opcionais de storefront para `view_item`, `view_item_list`, `view_cart`, `add_to_cart`, `remove_from_cart` e `begin_checkout`
- contrato enriquecido opcional do `dataLayer` do Woo para setups GTM-first
- dispatch server-side opcional para compras
- declaracao de compatibilidade com WooCommerce HPOS para armazenamento/rastreamento de pedidos

### Fontes de webhook (presentes no codigo; runtime nao verificado)

- Calendly
- HubSpot
- Typeform

### Chaves de adaptadores server-side (presentes no codigo / runtime nao verificado)

- Generic collector — relay para endpoint configurado
- sGTM — relay para endpoint configurado; hardening de SSRF do preview ainda esta pendente
- Meta CAPI — chave presente; contrato de API/autenticacao nao verificado em runtime
- Google Ads / GA4 — chave presente; contrato de API/autenticacao nao verificado em runtime
- LinkedIn CAPI — chave presente; contrato de API/autenticacao nao verificado em runtime
- Pinterest Conversions API — chave presente; contrato de API/autenticacao nao verificado em runtime
- TikTok Events API — chave presente; contrato de API/autenticacao nao verificado em runtime

Essas classes serializam o evento canonico para um endpoint configurado. Nao sao integracoes turnkey das
APIs dos provedores ate que fixtures especificas e evidencia de entrega em ambiente de teste passem.

### Destinos mediados por browser e GTM

- Google Tag Manager e configuracao do `dataLayer` do proprio site
- Meta/Facebook Pixel, Google tag/GA4, TikTok Pixel, LinkedIn Insight, Pinterest Tag e Reddit Pixel somente
  por um container GTM configurado pelo site; o ClickTrail nao injeta esses SDKs
- destino Reddit e captura de `rdt_cid` sao relay-only; nao existe adaptador nativo de entrega Reddit

Veja a [matriz completa](docs/reference/INTEGRATIONS.md#capability-matrix) para formularios, WooCommerce,
webhooks, consentimento e IDs de evidencia.

## Experiencia no Admin

A tela principal de configuracao agora e organizada por capacidade, e nao por nomes internos de implementacao:

- **Capture**: captura de origem, retencao e continuidade entre dominios
- **Forms**: diagnostico de formularios, WhatsApp e fontes externas
- **Events**: coleta no navegador, GTM, destinos e lifecycle
- **Delivery**: transporte server-side, privacidade e protecoes operacionais

As telas operacionais continuam separadas:

- **Logs**
- **Diagnostics**

As ferramentas operacionais agora incluem:

- checklist de setup em modo somente leitura dentro de Settings
- conflict scan interativo
- exportacao e restauracao de backup das configuracoes
- consulta de rastros de pedidos Woo para compras e marcos

Isso deixa a configuracao principal mais clara sem esconder saude de fila e ferramentas de debug.

## Privacidade e Consentimento

O ClickTrail possui controles de consentimento para atribuicao e eventos, mas a auditoria atual encontrou limites nao resolvidos em estado legado, revogacao, filas, WooCommerce, formularios e saida no dataLayer.

- Consent mode pode ser ligado ou desligado.
- O comportamento aceita `strict`, `relaxed` e `geo`.
- A fonte de consentimento pode ser auto, plugin, Cookiebot, OneTrust, Complianz, GTM ou custom.
- O plugin pode exibir seu proprio banner leve quando configurado como fonte de consentimento.

O plugin ajuda numa implementacao orientada a privacidade, mas a conformidade final depende da sua configuracao e do seu contexto juridico.

## Instalacao

### Antes de configurar

O ClickTrail pode ser adotado por partes. Uma configuracao basica para formularios ou WooCommerce nao exige entrega server-side logo no primeiro dia.

- Se voce so precisa da atribuicao dentro de formularios ou do WooCommerce, deixe a entrega server-side desligada por enquanto.
- Se o seu site ja injeta Google Tag Manager, nao preencha o container ID novamente dentro do ClickTrail.
- Se voce usa Gravity Forms ou WPForms, adicione antes os campos hidden `ct_*` que deseja armazenar ou exportar.
- Se o site exige consentimento, defina antes se a fonte principal sera o ClickTrail ou o CMP que voce ja usa.

### Configuracao inicial recomendada

1. Instale o plugin pelo WordPress ou envie-o para `/wp-content/plugins/click-trail-handler/`.
2. Ative o plugin e abra `ClickTrail > Settings`.
3. Em `Capture`, mantenha a atribuicao ligada, escolha uma janela de retencao compativel com o seu ciclo de venda e ative a continuidade cross-domain apenas se o visitante realmente passar por dominios ou subdominios aprovados.
4. Em `Forms`, ligue apenas as integracoes que voce usa. Contact Form 7 e Fluent Forms podem receber os campos de atribuicao automaticamente. Gravity Forms e WPForms devem ter os campos hidden `ct_*` que voce quer preservar, como `ct_ft_source`, `ct_lt_source` ou `ct_gclid`.
5. Em `Events`, deixe a coleta no navegador ligada apenas se voce quiser pushes para o `dataLayer` e captura de eventos no site. Ative os eventos de storefront do Woo apenas se quiser `view_item`, `view_item_list`, `view_cart`, `add_to_cart`, `remove_from_cart` e `begin_checkout`. Ative o contrato enriquecido de `dataLayer` do Woo apenas se quiser `event_id` e `user_data` sensivel a consentimento em fluxos GTM-first. Preencha o container ID do GTM apenas se o site ainda nao injeta GTM em outro lugar.
6. Em `Delivery`, deixe o server-side desligado se voce ainda nao tem collector, sGTM ou endpoint de destino pronto. Se houver exigencia de consentimento, escolha aqui a fonte e o modo corretos antes de colocar em producao.
7. Abra `ClickTrail > Diagnostics` e rode as verificacoes relevantes.

### Como validar que esta funcionando

1. Acesse o site com uma URL de teste, como `?utm_source=test&utm_medium=cpc&utm_campaign=clicktrail-install-check`.
2. Navegue para outra pagina e depois envie um formulario configurado ou faca um pedido de teste no WooCommerce.
3. Confirme o resultado esperado:
   - a entrada do formulario ou o pedido do WooCommerce contem os valores de atribuicao
   - os eventos aparecem no preview do GTM ou no `dataLayer` se `Events` estiver ligado
   - Diagnostics e Logs mostram atividade de intake ou delivery se `Delivery` estiver ligado

### Rollout padrao recomendado

Comece por `Capture` e pelas integracoes que ja estao em uso. Adicione `Events` depois, se quiser sinais de analytics no navegador. Adicione `Delivery` apenas quando estiver pronto para enviar dados para um collector ou endpoint de publicidade.

## Casos de Uso Comuns

- [agencias e negocios de servico que precisam da origem nos leads](docs/guides/USE-CASES.md#lead-generation-forms)
- [lojas WooCommerce que querem pedidos com atribuicao de campanha](docs/guides/USE-CASES.md#woocommerce-orders)
- [sites com cache agressivo ou formularios dinamicos](docs/guides/USE-CASES.md#cached-and-dynamic-forms)
- [negocios com funis em multiplos dominios aprovados](docs/guides/USE-CASES.md#approved-multi-domain-funnels)
- [equipes que precisam alinhar captura com uma fonte de consentimento existente](docs/guides/USE-CASES.md#consent-aware-sites)

Se voce precisa de call tracking, lead scoring, modelagem de receita multi-touch ou otimizacao de investimento em anuncios, o ClickTrail nao e essa ferramenta; combine-o com a plataforma especializada que voce ja usa.

## Tutoriais

- [Atribuicao em formularios](docs/tutorials/01-lead-form-attribution.md)
- [Atribuicao de pedidos WooCommerce](docs/tutorials/02-woocommerce-order-attribution.md)
- [Consentimento e eventos no navegador](docs/tutorials/03-consent-and-events.md)

## Fases de release e evidencias

O [plano de fases](docs/guides/RELEASE-PHASING-AND-INTEGRATION-DOCS.md) separa documentacao de verdade,
remediacao de consentimento/privacidade, integridade de entrega, releases por provedor e o trabalho posterior de alcance.

## Documentacao do Repositorio

- [Playbook de implementacao](docs/guides/IMPLEMENTATION-PLAYBOOK.md)
- [Indice da documentacao tecnica](docs/README.md)
- [Guia de contribuicao](CONTRIBUTING.pt-BR.md)
- [Referencia de integracoes](docs/reference/INTEGRATIONS.md)
- [Readme do WordPress.org](readme.txt)
- [Roadmap de posicionamento competitivo e aquisicao](docs/guides/COMPETITIVE-POSITIONING-AND-ACQUISITION-ROADMAP-2026-08-22.md)

## Notas Sobre a Arquitetura Atual

- A interface publica do admin nao usa mais a terminologia "Tracking v2".
- Internamente, parte das configuracoes ainda fica na option `clicutcl_tracking_v2` por compatibilidade.
- O controlador legado de logs da API v1 foi removido; `clicutcl/v2` e o unico namespace REST ativo. Veja a [referencia da API REST](docs/reference/REST-API.md).

## Licenca

GPL-2.0-or-later. Veja [LICENSE](LICENSE).
