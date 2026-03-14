# ClickTrail

ClickTrail e um plugin de atribuicao para WordPress feito para sites que precisam manter a origem real das conversoes ao longo da jornada completa, especialmente quando pedidos do WooCommerce ou formularios acontecem varias paginas depois da landing page.

Ele foi pensado para os problemas que normalmente quebram a atribuicao em producao:

- paginas com cache
- formularios dinamicos ou carregados via AJAX
- jornadas com varias paginas ou varias sessoes
- fluxos entre dominios
- necessidade de tracking com consentimento
- entrega opcional server-side

Em vez de capturar uma UTM uma vez e torcer para que ela sobreviva, o ClickTrail mantem o contexto de primeiro toque e ultimo toque disponivel ate o momento em que pedidos do WooCommerce, formularios, eventos no navegador ou fluxos de entrega realmente precisam dele.

## O Que o ClickTrail Faz

O ClickTrail captura atribuicao de primeiro toque e ultimo toque, mantem esses dados disponiveis durante a jornada do visitante e faz com que essa informacao chegue ao ponto em que a conversao realmente acontece dentro do WordPress.

Ele combina:

- captura de atribuicao
- atribuicao em pedidos do WooCommerce com payload de compra enriquecido
- enriquecimento de formularios
- coleta de eventos no navegador
- controles de consentimento
- transporte server-side opcional com fila e diagnosticos

Isso permite comecar por pedidos do WooCommerce com atribuicao confiavel ou por formularios, e adicionar eventos no navegador, integracoes de consentimento ou entrega server-side depois, quando a operacao realmente precisar.

## Problemas Que Ele Resolve

### 1. Perda de atribuicao dentro do WordPress

O usuario entra com UTMs ou click IDs, navega algumas paginas e converte depois. Outros visitantes chegam por busca organica ou redes sociais sem tags. Sem persistencia, o formulario ou pedido perde a origem original.

O ClickTrail mantem a trilha da origem disponivel em formularios, checkout e payloads de evento.

### 2. Cache e formularios dinamicos quebrando campos ocultos

Muitos plugins de atribuicao dependem apenas de campos hidden renderizados no servidor. Isso falha quando a pagina esta em cache ou quando o formulario entra depois do carregamento.

O ClickTrail inclui fallback client-side e observacao de conteudo dinamico para continuar levando a atribuicao aos formularios suportados e aos campos hidden compativeis.

### 3. Pedidos do WooCommerce sem origem confiavel

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

- enriquecimento automatico de campos hidden no Contact Form 7 e no Fluent Forms
- preenchimento compativel de campos hidden ja existentes no Gravity Forms e no WPForms
- recomendado para Gravity Forms e WPForms: adicione os campos hidden que deseja armazenar ou exportar, e o ClickTrail faz o preenchimento
- fallback client-side para paginas com cache
- deteccao de formularios dinamicos
- opcao para substituir valores de atribuicao ja existentes
- suporte para append de atribuicao no WhatsApp
- intake de webhooks de fontes externas suportadas

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

## Integracoes Suportadas

### WordPress e frontend

- WordPress 6.5+
- PHP 8.1+
- banner de consentimento proprio quando o plugin e a fonte de consentimento
- injecao opcional de container do GTM
- modo de compatibilidade sGTM com URL do tagging server, entrega first-party do script e suporte a custom loader

### Formularios

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

### Comercio

- atribuicao em pedidos do WooCommerce
- push enriquecido do evento de compra para o `dataLayer`
- eventos opcionais de storefront para `view_item`, `view_item_list`, `view_cart`, `add_to_cart`, `remove_from_cart` e `begin_checkout`
- contrato enriquecido opcional do `dataLayer` do Woo para setups GTM-first
- dispatch server-side opcional para compras
- declaracao de compatibilidade com WooCommerce HPOS para armazenamento/rastreamento de pedidos

### Provedores externos

- Calendly
- HubSpot
- Typeform

### Adaptadores server-side

- Generic collector
- sGTM
- Meta CAPI
- Google Ads / GA4
- LinkedIn CAPI
- Pinterest Conversions API
- TikTok Events API

## Experiencia no Admin

A tela principal de configuracao agora e organizada por capacidade, e nao por nomes internos de implementacao:

- **Capture**: captura de origem, retencao e continuidade entre dominios
- **Forms**: confiabilidade de formularios, WhatsApp e fontes externas
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

O ClickTrail oferece suporte a atribuicao e eventos com regras de consentimento.

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
2. Navegue para outra pagina e depois envie um formulario suportado ou faca um pedido de teste no WooCommerce.
3. Confirme o resultado esperado:
   - a entrada do formulario ou o pedido do WooCommerce contem os valores de atribuicao
   - os eventos aparecem no preview do GTM ou no `dataLayer` se `Events` estiver ligado
   - Diagnostics e Logs mostram atividade de intake ou delivery se `Delivery` estiver ligado

### Rollout padrao recomendado

Comece por `Capture` e pelas integracoes que ja estao em uso. Adicione `Events` depois, se quiser sinais de analytics no navegador. Adicione `Delivery` apenas quando estiver pronto para enviar dados para um collector ou endpoint de publicidade.

## Casos de Uso Comuns

- agencias que precisam da origem dentro dos leads
- lojas WooCommerce que querem pedidos com atribuicao confiavel
- lojas WooCommerce que querem payloads de compra mais ricos sem trocar toda a stack de tracking
- sites com cache agressivo ou formularios dinamicos
- negocios com funis em multiplos dominios
- equipes que querem tracking browser + server-side no mesmo plugin

## Documentacao do Repositorio

- [Playbook de implementacao](docs/guides/IMPLEMENTATION-PLAYBOOK.md)
- [Indice da documentacao tecnica](docs/README.md)
- [Guia de contribuicao](CONTRIBUTING.pt-BR.md)
- [Referencia de integracoes](docs/reference/INTEGRATIONS.md)
- [Readme do WordPress.org](readme.txt)

## Notas Sobre a Arquitetura Atual

- A interface publica do admin nao usa mais a terminologia "Tracking v2".
- Internamente, parte das configuracoes ainda fica na option `clicutcl_tracking_v2` por compatibilidade.
- O controlador legado da API v1 ainda existe no repositorio, mas fica desligado por padrao, a menos que `CLICUTCL_ENABLE_LEGACY_V1_API` seja habilitado explicitamente.

## Licenca

GPL-2.0-or-later. Veja [LICENSE](LICENSE).
