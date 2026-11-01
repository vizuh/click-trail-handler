# ClickTrail

[![WordPress tested](https://img.shields.io/badge/WordPress-v7.0%20tested-3858e9.svg)](https://wordpress.org)

![ClickTrail](.github/clicktrail-cover.png)

**Mantenha a campanha ligada à conversão.**

Alguém clica no seu anúncio. Três páginas depois, envia um formulário ou faz um pedido. A essa altura a campanha quase sempre se perdeu, e o lead parece ter vindo do nada.

O ClickTrail guarda aquilo com que o visitante chegou: as UTMs, o ID de clique do anúncio, o referenciador. Ele leva esse contexto até o envio do formulário ou o pedido do WooCommerce, e também até um agendamento no Calendly. Você abre o registro e vê de onde ele veio.

O ClickTrail registra o que chegou. Ele não decide o que causou a venda.

## Por onde começar

Escolha o caminho que combina com o seu site.

- **Loja WooCommerce**: ative Capture e WooCommerce. Os pedidos passam a levar a campanha. Ative os eventos de vitrine depois, se quiser sinais de funil.
- **Formulários de leads**: ative Capture e Forms. O Fluent Forms recebe os campos ocultos sozinho; o Contact Form 7 preenche as tags `ct_*` que o formulário ou o e-mail referenciar (`[hidden ct_gclid]` ou `[ct_gclid]`). Gravity Forms e WPForms preenchem os campos `ct_*` que você adicionar (o WPForms Lite não tem campo Hidden: use um campo de Texto de Linha Única com a classe CSS `ct_<chave>` e oculte-o com CSS).
- **GTM ou GTM server-side**: ative Capture, Events e Delivery. Os eventos vão para o `dataLayer`, e o ClickTrail pode enviar JSON canônico para um endpoint que você definir.

Depois, faça um pedido de teste ou envie um formulário de teste e veja o que ficou salvo. O [guia de casos de uso](docs/guides/USE-CASES.md) e os [tutoriais](docs/tutorials/README.md) mostram cada caminho.

## O que ele faz

O ClickTrail só age nos caminhos que você ativa e ajusta nas configurações dele. É isso que "configurado" significa em toda esta documentação.

- Captura: UTMs de primeiro e último toque, IDs de clique de anúncios e o referenciador, com fallback para orgânico, social e referência quando não há UTMs. Quando um visitante volta por um novo anúncio, é o clique mais recente que segue adiante. O contexto fica em armazenamento first-party, usa o `sessionStorage` quando os cookies estão bloqueados, e a sua própria navegação como administrador fica de fora.
- WooCommerce: atribuição salva no pedido antes do redirecionamento para um pagamento externo (checkout clássico e em blocos), sem que a volta de hosts de pagamento como PayPal ou Stripe sobrescreva o toque guardado, um evento de compra enriquecido na página de agradecimento, eventos de vitrine opcionais (`view_item`, `view_item_list`, `view_cart`, `add_to_cart`, `remove_from_cart`, `begin_checkout`) e marcos opcionais de status do pedido. Os metadados do pedido têm exportação, exclusão, retenção e tratamento na desinstalação.
- Formulários: O Fluent Forms recebe campos ocultos automaticamente; o Contact Form 7 preenche as tags `ct_*` referenciadas (`[hidden ct_gclid]` ou `[ct_gclid]` no e-mail). Gravity Forms e WPForms preenchem os campos `ct_*` que você adicionar; o Gravity Forms também ganha um rótulo de canal, nove merge tags `{clicutcl_*}` e uma chave por formulário. Elementor Forms (Pro) e Ninja Forms guardam a atribuição junto com o registro do envio. Um fallback client-side cobre páginas em cache e formulários que carregam depois, e links do WhatsApp também podem levar o contexto.
- Agendamentos e seus outros domínios: links para domínios que você aprova levam a campanha, com um token assinado entre os seus próprios sites. Os links de agendamento do Calendly levam o clique mais recente e a decisão de consentimento do visitante, e o webhook assinado do Calendly registra o agendamento como `book_appointment` com as UTMs. Iframes de agendamento ou formulário que o seu próprio script carrega também podem levar a campanha, via `window.ClickTrail.decorateEmbedUrl()`, para os hosts que você listar.
- Eventos e entrega: eventos do navegador para o `dataLayer`, uma entrada REST, webhooks de Calendly, HubSpot e Typeform, atualizações de ciclo de vida e eventos únicos do WordPress como `login`, `sign_up` e `comment_submit`. A entrega server-side opcional adiciona fila de novas tentativas e diagnósticos.

## Consentimento e privacidade

Quando o consentimento é obrigatório, nada é armazenado até o visitante aceitar. O ClickTrail escuta o próprio banner, Cookiebot, OneTrust, Complianz, GTM ou uma fonte personalizada, ou detecta a fonte para você. O ClickTrail segue as decisões do Cookiebot e do Complianz, inclusive mudanças que o visitante faz na própria página. Quando o consentimento é retirado, o ClickTrail limpa o que armazenou e remove dos links os parâmetros que ele mesmo adicionou. Itens na fila de entrega verificam o consentimento atual de novo logo antes do envio, e a decisão de consentimento fica sincronizada entre abas. O comportamento pode ser strict, relaxed ou por região (geo). O ClickTrail ajuda você a implementar o consentimento; a conformidade continua dependendo das suas exigências legais e das suas configurações.

## O que ele não faz

- Não prova qual clique causou uma venda e não decide qual canal merece a receita.
- Não resolve a identidade de uma pessoa entre dispositivos.
- Não é um painel de atribuição, uma plataforma hospedada de GTM server-side, um gestor de leads nem um otimizador de anúncios. Ele trabalha ao lado do GA4 e do GTM. Para call tracking, lead scoring ou otimização de investimento em anúncios, combine-o com uma ferramenta especializada.
- Não instala pixels de anúncios. Meta Pixel, Google tag, TikTok Pixel, LinkedIn Insight Tag, Pinterest Tag e Reddit Pixel continuam com você, normalmente via GTM.
- A entrega server-side envia JSON para o endpoint que você configurar. Os adaptadores com nome de Meta, Google Ads / GA4, LinkedIn, Pinterest e TikTok existem no código, mas ainda não verificamos se algum provedor aceita esses envios de ponta a ponta. O Reddit é um destino apenas de repasse (relay-only).
- Ainda não verificamos um agendamento real no Calendly de ponta a ponta.

Ter código para uma integração não prova que ela funciona em produção. O status atual está na [referência de integrações](docs/reference/INTEGRATIONS.md) e no [ledger legível por máquina](docs/reference/integration-capabilities.json). O [plano de fases](docs/guides/RELEASE-PHASING-AND-INTEGRATION-DOCS.md) mostra o que será verificado em seguida.

## Referência

**IDs de clique suportados**

| Plataforma | Parâmetros |
|---|---|
| Google | `gclid`, `wbraid`, `gbraid` |
| Meta | `fbclid` |
| TikTok | `ttclid` |
| Microsoft | `msclkid` |
| X / Twitter | `twclid` |
| LinkedIn | `li_fat_id` |
| Snapchat | `sccid`, `snap_cid` |
| Pinterest | `epik`, `pin_cid` |
| Reddit | `rdt_cid` |
| Mailchimp | `mc_cid`, `mc_eid` |
| Display & Video 360 | `dclid` |

**Integrações**

- **Formulários**: Contact Form 7, Elementor Forms (Pro), Fluent Forms, Gravity Forms, Ninja Forms, WPForms
- **Comércio**: WooCommerce
- **Fontes de consentimento**: banner do ClickTrail, Cookiebot, OneTrust, Complianz, GTM, personalizada
- **Webhooks**: Calendly, HubSpot, Typeform
- **Chaves de adaptadores server-side**: coletor genérico, sGTM, Meta CAPI, Google Ads / GA4, LinkedIn CAPI, Pinterest Conversions API, TikTok Events API (status acima)
- **Campos extras capturados**: `utm_id`, `utm_source_platform`, `utm_creative_format`, `utm_marketing_tactic`, `fbc`, `fbp`, `_ttp`, `li_gc`, `ga_client_id`, `ga_session_id`
- **Cache e minificação**: em todos os sites, as quatro tags de script do ClickTrail (`clicutcl-consent-bridge-js`, `clicutcl-attribution-js`, `clicutcl-consent-js`, `clicutcl-events-js`) têm `nowprocket data-no-optimize="1" data-no-defer="1" data-noptimize="1" data-cfasync="false"`: `nowprocket` (WP Rocket Delay JS), `data-no-optimize="1"` e `data-no-defer="1"` (LiteSpeed Cache), `data-noptimize="1"` (Autoptimize), `data-cfasync="false"` (Cloudflare Rocket Loader). Os mesmos scripts entram nos filtros de exclusão de WP Rocket, LiteSpeed Cache, Autoptimize, Perfmatters e SiteGround Speed Optimizer; o NitroPack recebe `data-nitropack-exclude="true"` quando ativo. Para conferir: veja o código-fonte da página, confirme os atributos em cada tag `clicutcl-*.js` e confirme que `window.ClickTrail` existe antes de enviar um formulário. O FlyingPress não expõe atributo nem filtro: adicione `clicutcl` e `clicktrail-consent-mode` ao campo de exclusão dele. Presente no código / não verificado em runtime.

A versão atual é a 1.11.3, no GitHub e no WordPress.org. O histórico está no [changelog](changelog.txt).

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
4. Em `Forms`, ligue apenas as integracoes que voce usa. O Fluent Forms pode receber os campos de atribuicao automaticamente; o Contact Form 7 so renderiza um campo para cada tag `ct_*` que o formulario ou o e-mail referenciar. Gravity Forms, WPForms e Elementor Forms (Pro) devem ter os campos hidden `ct_*` que voce quer preservar, como `ct_ft_source`, `ct_lt_source` ou `ct_gclid` (clique mais recente; use `ct_ft_gclid` ou `ct_lt_gclid` para fixar o toque): o Gravity Forms le o nome do parametro, o WPForms uma classe CSS (ou o rotulo) `ct_<key>`, o Elementor o ID do campo. Todos os adaptadores atualizam esses campos a partir do cookie enviado com o submit, entao um envio na pagina de entrada guarda o clique daquela visita.
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

## Tutoriais

- [Atribuicao em formularios](docs/tutorials/01-lead-form-attribution.md)
- [Atribuicao de pedidos WooCommerce](docs/tutorials/02-woocommerce-order-attribution.md)
- [Consentimento e eventos no navegador](docs/tutorials/03-consent-and-events.md)

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
