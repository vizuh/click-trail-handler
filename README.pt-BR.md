# ClickTrail

ClickTrail e um plugin de atribuicao e tracking para WordPress feito para sites que precisam manter a origem real das conversoes em formularios, pedidos do WooCommerce e pipelines de eventos.

Ele foi pensado para os problemas que normalmente quebram a atribuicao em projetos reais:

- paginas com cache
- formularios dinamicos ou carregados via AJAX
- jornadas com varias paginas ou varias sessoes
- fluxos entre dominios
- necessidade de tracking com consentimento
- entrega opcional server-side

## O Que o ClickTrail Faz

O ClickTrail captura atribuicao de primeiro toque e ultimo toque, mantem esses dados disponiveis durante a jornada do visitante e faz com que essa informacao chegue ao ponto em que a conversao realmente acontece.

Ele combina:

- captura de atribuicao
- enriquecimento de formularios
- atribuicao em pedidos do WooCommerce
- coleta de eventos no navegador
- controles de consentimento
- transporte server-side opcional com fila e diagnosticos

## Problemas Que Ele Resolve

### 1. Perda de atribuicao dentro do WordPress

O usuario entra com UTMs ou click IDs, navega algumas paginas e converte depois. Sem persistencia, o formulario ou pedido perde a origem original.

O ClickTrail mantem a trilha da origem disponivel em formularios, checkout e payloads de evento.

### 2. Cache e formularios dinamicos quebrando campos ocultos

Muitos plugins de atribuicao dependem apenas de campos hidden renderizados no servidor. Isso falha quando a pagina esta em cache ou quando o formulario entra depois do carregamento.

O ClickTrail inclui fallback client-side e observacao de conteudo dinamico para continuar preenchendo a atribuicao.

### 3. Pedidos do WooCommerce sem origem confiavel

Trafego pago frequentemente acaba aparecendo como direto dentro dos pedidos.

O ClickTrail grava a atribuicao no pedido e envia o evento de compra para o `dataLayer`, com dispatch server-side opcional.

### 4. Jornadas entre dominios perdendo continuidade

Quando o usuario sai do site principal para app, agenda, checkout ou outro subdominio, a atribuicao costuma reiniciar.

O ClickTrail oferece decoracao de links entre dominios permitidos e continuidade via token de atribuicao.

### 5. Consentimento e entrega separados em ferramentas diferentes

Muitas equipes precisam que privacidade, captura de evento e entrega conversem entre si.

O ClickTrail junta consentimento, intake de eventos e configuracao de entrega no mesmo plugin.

## Capacidades Principais

### Capture

- UTMs de primeiro toque e ultimo toque
- captura de referrer
- captura dos principais click IDs de anuncios
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

### Forms

- enriquecimento de campos hidden em plugins de formulario suportados
- fallback client-side para paginas com cache
- deteccao de formularios dinamicos
- opcao para substituir valores de atribuicao ja existentes
- suporte para append de atribuicao no WhatsApp
- intake de webhooks de fontes externas suportadas

### Events

- coleta de eventos no navegador
- pushes para `dataLayer` em formato amigavel para GA4
- eventos de busca, download, scroll, tempo na pagina e interacoes de lead gen
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

### Formularios

- Contact Form 7
- Fluent Forms
- Gravity Forms
- Ninja Forms
- WPForms

### Comercio

- atribuicao em pedidos do WooCommerce
- push do evento de compra para o `dataLayer`
- dispatch server-side opcional para compras

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

## Experiencia no Admin

A tela principal de configuracao agora e organizada por capacidade, e nao por nomes internos de implementacao:

- **Capture**: captura de origem, retencao e continuidade entre dominios
- **Forms**: confiabilidade de formularios, WhatsApp e fontes externas
- **Events**: coleta no navegador, GTM, destinos e lifecycle
- **Delivery**: transporte server-side, privacidade e protecoes operacionais

As telas operacionais continuam separadas:

- **Logs**
- **Diagnostics**

Isso deixa a configuracao principal mais clara sem esconder saude de fila e ferramentas de debug.

## Privacidade e Consentimento

O ClickTrail oferece suporte a atribuicao e eventos com regras de consentimento.

- Consent mode pode ser ligado ou desligado.
- O comportamento aceita `strict`, `relaxed` e `geo`.
- A fonte de consentimento pode ser auto, plugin, Cookiebot, OneTrust, Complianz, GTM ou custom.
- O plugin pode exibir seu proprio banner leve quando configurado como fonte de consentimento.

O plugin ajuda numa implementacao orientada a privacidade, mas a conformidade final depende da sua configuracao e do seu contexto juridico.

## Instalacao

1. Envie o plugin para `/wp-content/plugins/click-trail-handler/` ou instale pelo WordPress.
2. Ative o plugin.
3. Abra `ClickTrail > Settings`.
4. Configure as areas que voce realmente usa:
   - `Capture` para atribuicao
   - `Forms` para formularios
   - `Events` para eventos e destinos
   - `Delivery` para transporte e privacidade
5. Valide o ambiente em `ClickTrail > Diagnostics`.

## Casos de Uso Comuns

- agencias que precisam da origem dentro dos leads
- lojas WooCommerce que querem pedidos com atribuicao confiavel
- sites com cache agressivo ou formularios dinamicos
- negocios com funis em multiplos dominios
- equipes que querem tracking browser + server-side no mesmo plugin

## Documentacao do Repositorio

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
