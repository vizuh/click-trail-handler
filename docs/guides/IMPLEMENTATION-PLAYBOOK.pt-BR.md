# ClickTrail — Guia de Implementação

- **Público**: times de implementação, agências digitais, analistas de marketing, suporte técnico
- **Use este guia quando**: alguém perguntar "como configuro o ClickTrail do zero?" ou "como conecto ao meu CRM?"
- **Última revisão**: 1.7.1

---

## Princípios Básicos

1. Não ative tudo de uma vez.
2. Comece pela superfície de conversão que você já tem: formulários ou WooCommerce.
3. Trate `Captura`, `Formulários`, `Eventos` e `Entrega` como módulos independentes.
4. Escolha uma única fonte de verdade para o consentimento.
5. Não carregue o GTM duas vezes.

A sequência que funciona para a maioria dos times:

1. preservar a atribuição (UTMs sobrevivem além da landing page)
2. tornar a atribuição visível no ponto de conversão (formulário ou pedido)
3. adicionar contexto de eventos de navegador
4. adicionar entrega server-side só quando um destino estiver pronto

---

## O que cada módulo entrega

### Captura

Quando usar:
- o site tem jornadas de múltiplas páginas ou visitas repetidas
- UTMs precisam sobreviver a cliques em menus, redirecionamentos ou trocas de domínio

O que o time ganha:
- first-touch e last-touch armazenados em storage próprio (cookie + localStorage)
- classificação automática de canal: Google Ads, Meta Ads, Orgânico, Direto, etc.
- continuidade entre domínios aprovados

### Formulários

Quando usar:
- as conversões principais acontecem via formulário de leads
- o site usa páginas em cache ou formulários renderizados dinamicamente

O que o time ganha:
- atribuição anexada ao envio do formulário
- campos ocultos (`ct_*`) preenchidos automaticamente nos formulários suportados

### Eventos

Quando usar:
- o time precisa de sinais de comportamento no `dataLayer`
- GTM ou outra plataforma de analytics precisa de eventos de busca, rolagem, engajamento ou geração de leads

O que o time ganha:
- coleta de eventos no navegador
- payload canônico de eventos
- intake via REST para eventos prontos para entrega

### Entrega Server-Side

Quando usar:
- já existe um coletor, endpoint sGTM, ou destino de ad-platform
- o time precisa de retentativas, fila visível e diagnóstico de entrega

O que o time ganha:
- despacho server-side
- filas com retentativas
- verificação de saúde do endpoint
- visibilidade operacional em Logs e Diagnósticos

---

## Padrões de Implementação

### 1. Site de Geração de Leads com Formulários

Ideal para:
- agências digitais
- sites B2B com formulários como principal ponto de conversão
- empresas de serviço (clínicas, escritórios, SaaS)

Ative:
- `Captura`
- `Formulários`

Deixe desativado por enquanto:
- `Entrega`, a menos que já exista um destino server-side configurado

Comportamento por plugin de formulário:

| Plugin | Comportamento |
|--------|---------------|
| Contact Form 7 | ClickTrail adiciona campos ocultos automaticamente |
| Fluent Forms | ClickTrail adiciona campos ocultos automaticamente |
| Gravity Forms | Você precisa adicionar campos ocultos `ct_*` manualmente |
| WPForms | Você precisa adicionar campos ocultos `ct_*` manualmente |
| Elementor Forms (Pro) | Usa hooks de submissão e atribuição de fallback |
| Ninja Forms | Usa storage de submissão, sem injeção de campo automática |

Validação:
1. Acesse uma página com uma URL com UTMs de teste (`?utm_source=teste&utm_medium=cpc`).
2. Navegue para outra página do site.
3. Envie um formulário.
4. Confirme que a atribuição aparece no registro de entrada do formulário.

---

### 2. Loja WooCommerce

Ideal para:
- lojas que querem contexto de campanha nos pedidos dentro do WordPress
- times que precisam de atribuição de compra sem montar um pipeline server-side

Ative:
- `Captura`
- Integração WooCommerce

Próximo passo opcional:
- `Eventos` para sinais de navegador relacionados a compras
- `Entrega` para despacho server-side de compras

Validação:
1. Acesse a loja com uma URL de campanha marcada.
2. Navegue por produtos e finalize um pedido de teste.
3. Confirme que o pedido registra a atribuição.
4. Se eventos de navegador estiverem ativos, confirme sinais de compra no GTM Preview ou no `dataLayer`.

---

### 3. Funil Multi-Domínio

Ideal para:
- site de marketing → app em outro domínio
- site de marketing → agendamento externo
- site de marketing → checkout em subdomínio separado

Ative:
- `Captura`
- Continuidade entre domínios apenas para os domínios aprovados

Sequência recomendada:
1. configure os domínios permitidos com cuidado
2. ative a decoração de links ou continuidade por token assinado apenas onde necessário
3. verifique se o domínio receptor preserva a atribuição em vez de resetá-la

Validação:
1. Entre no primeiro domínio com uma URL marcada.
2. Siga o fluxo real entre domínios.
3. Confirme que o formulário ou pedido final ainda tem o rastro de origem original.

---

### 4. Site com Plataforma de Consentimento (CMP)

Ideal para:
- sites usando CookieYes, Cookiebot, OneTrust, Complianz ou fluxo de consentimento via GTM

Ative:
- `Captura`
- Modo de consentimento apenas se o site precisar de controle por consentimento

Abordagem recomendada:
1. mantenha uma única fonte de verdade para o consentimento
2. aponte o ClickTrail para o CMP já usado pelo site
3. valide o fluxo com consentimento concedido e negado antes do lançamento

Validação:
1. teste com consentimento concedido
2. teste com consentimento negado
3. confirme que atribuição e eventos de navegador se comportam conforme o modo configurado

---

### 5. Webhook para CRM — envio de leads com dados de marketing (PipeRun, HubSpot, RD Station)

**Palavras-chave:** utm no crm, rastreamento de leads wordpress, atribuição de marketing webhook, piperun utm, hubspot webhook wordpress, rd station utm, como passar utm para o crm

Ideal para:
- sites que enviam leads para um CRM via webhook disparado no navegador
- integrações que usam Elementor Forms, scripts customizados ou plugins de formulário com JavaScript próprio
- qualquer fluxo em que o envio do lead é feito via `fetch()` ou `XMLHttpRequest` direto para um endpoint externo

Ative:
- `Captura` (obrigatório — é o módulo que lê UTMs, gclid, fbclid e canal na entrada do visitante)
- `Formulários` (opcional — para registrar o envio também no WordPress)

O ClickTrail não interfere no envio do webhook. O que ele faz é capturar os dados de marketing na chegada do visitante e disponibilizá-los via `window.ClickTrail.getField()` para que o script de envio leia antes de montar o payload.

---

#### Arquitetura — três camadas

A maioria das integrações com CRM em produção segue este fluxo:

```
Formulário no site
       ↓
Script JavaScript (coleta campos + atribuição ClickTrail)
       ↓
Webhook middleware (PHP no servidor — valida, transforma, aciona automações)
       ↓
API do CRM (PipeRun, HubSpot, RD Station...)
```

Entender essa separação é importante porque **adicionar um campo novo exige alterar as três camadas**:

| Camada | O que precisa mudar |
|--------|---------------------|
| Script JavaScript | Ler o campo via `window.ClickTrail.getField()` e incluir no payload |
| Webhook middleware | Receber o campo do payload e repassá-lo na chamada à API do CRM |
| CRM | Ter o campo personalizado criado antes de receber o valor |

Se o site envia direto para a API do CRM sem middleware, a camada do webhook não existe — apenas as camadas 1 e 3 precisam mudar.

---

#### Como ler a atribuição no script de envio

```js
function getAttribution(key) {
  if (window.ClickTrail && typeof window.ClickTrail.getField === 'function') {
    var val = window.ClickTrail.getField(key);
    if (val) return val;
  }
  return new URLSearchParams(window.location.search).get(key) || '';
}

function ft(key) {
  return window.ClickTrail ? (window.ClickTrail.getField('ft_' + key) || getAttribution('utm_' + key)) : getAttribution('utm_' + key);
}
function lt(key) {
  return window.ClickTrail ? (window.ClickTrail.getField('lt_' + key) || '') : '';
}
function ct(key) {
  return window.ClickTrail ? (window.ClickTrail.getField(key) || '') : '';
}
```

---

#### Campos disponíveis via `window.ClickTrail.getField()`

| Campo | Descrição |
|-------|-----------|
| `ft_source` | Fonte da primeira visita (ex.: `facebook`, `google`) |
| `ft_medium` | Mídia da primeira visita (ex.: `cpc`, `paid_social`) |
| `ft_campaign` | Campanha da primeira visita |
| `ft_term` | Palavra-chave da primeira visita |
| `ft_content` | Variação do anúncio |
| `ft_channel` | Canal resolvido: `Google Ads`, `Facebook Ads`, `Organic Search`, `Direct`, etc. |
| `ft_landing_page` | Landing page da primeira visita |
| `lt_source` | Fonte da visita que converteu |
| `lt_medium` | Mídia da visita que converteu |
| `lt_campaign` | Campanha que converteu |
| `lt_channel` | Canal que converteu |
| `gclid` | Google Click ID — necessário para importar conversões no Google Ads |
| `wbraid` | Click ID Google (iOS / consent mode) |
| `gbraid` | Click ID Google (apps / consent mode) |
| `fbclid` | Facebook Click ID |
| `fbc` | Formato `fb.1.timestamp.fbclid` — usado pelo Facebook CAPI |
| `fbp` | Cookie do Facebook Pixel — usado pelo Facebook CAPI |

Os campos `gclid`, `fbclid`, `fbc` e `fbp` só têm valor quando o lead veio de um anúncio pago. Em tráfego orgânico ficam vazios — comportamento esperado.

---

#### Exemplo de payload completo com atribuição

```js
var payload = {
  // dados do formulário...
  nome:  document.querySelector('[name="nome"]').value,
  email: document.querySelector('[name="email"]').value,

  // first touch — de onde veio o lead pela primeira vez
  utm_source:   ft('source'),
  utm_medium:   ft('medium'),
  utm_campaign: ft('campaign'),
  utm_term:     ft('term'),
  utm_content:  ft('content'),
  ft_channel:   ct('ft_channel'),
  landing_page: ct('ft_landing_page'),

  // click IDs — Google Ads e Facebook Ads
  gclid:  getAttribution('gclid'),
  fbclid: ct('fbclid'),
  fbc:    ct('fbc'),
  fbp:    ct('fbp'),

  // last touch — campanha activa quando o lead converteu
  lt_utm_source:   lt('source'),
  lt_utm_medium:   lt('medium'),
  lt_utm_campaign: lt('campaign'),
  lt_channel:      ct('lt_channel'),

  referrer: document.referrer || ''
};
```

---

#### Campos a criar no CRM

Os campos personalizados precisam existir no CRM antes do primeiro envio. A maioria dos CRMs não cria campos automaticamente — ignora silenciosamente o que não reconhece.

Campos recomendados para times que rodam Google Ads e Meta Ads:

| Campo | Tipo | Descrição |
|-------|------|-----------|
| `utm_source` | Texto | Fonte da campanha |
| `utm_medium` | Texto | Mídia da campanha |
| `utm_campaign` | Texto | Nome da campanha |
| `utm_content` | Texto | Variação do anúncio |
| `ft_channel` | Texto | Canal resolvido pelo ClickTrail |
| `landing_page` | Texto | URL de entrada |
| `gclid` | Texto | ID do clique Google Ads |
| `fbclid` | Texto | ID do clique Facebook Ads |
| `fbc` | Texto | Formato Facebook CAPI |
| `fbp` | Texto | Cookie Facebook Pixel |
| `lt_utm_source` | Texto | Fonte da visita que converteu |
| `lt_utm_medium` | Texto | Mídia da visita que converteu |
| `lt_utm_campaign` | Texto | Campanha da visita que converteu |
| `lt_channel` | Texto | Canal da visita que converteu |

No PipeRun os campos ficam em **Configurações → Campos personalizados**. Após criar, cada campo recebe um ID numérico que o webhook middleware precisa referenciar ao chamar a API do PipeRun.

---

#### Erros comuns neste padrão

- **Script envia os campos mas o CRM mostra vazio** — o campo personalizado não foi criado no CRM antes do primeiro envio.
- **Webhook middleware ignora os campos novos** — o PHP do webhook não foi atualizado para ler e repassar os campos na chamada à API do CRM.
- **`window.ClickTrail` é `undefined`** — o módulo Captura não está ativo, ou o script dispara antes do ClickTrail carregar. Usar o evento `ct_ready` resolve:

```js
document.addEventListener('ct_ready', function() {
  // seguro ler window.ClickTrail.getField() aqui
});
```

---

Validação:
1. Acesse o site com `?utm_source=google&utm_medium=cpc&utm_campaign=teste`.
2. Navegue para outra página antes de converter (valida persistência do first touch).
3. Envie o formulário e abra o console — confirme que `ft_channel` está preenchido com `Google Ads`.
4. Verifique no CRM que o lead chegou com os campos UTM e o canal corretos.

### 6. Entrega Server-Side

Ideal para:
- times operando sGTM, um coletor próprio, ou entrega para plataformas de anúncio
- times que precisam de retentativas, fila e diagnóstico de entrega

Ative:
- `Entrega`

Somente após:
- URL do endpoint estar pronta
- escolha do adaptador estar definida
- comportamento de consentimento estar validado

Roteiro recomendado:
1. ative entrega em staging primeiro
2. execute verificações de saúde do endpoint
3. valide um caminho de evento bem-sucedido
4. confirme que retentativas de fila se comportam como esperado em falhas forçadas
5. então ative em produção

Validação:
1. confirme que `Diagnósticos > Teste de Endpoint` passa
2. envie um evento de teste conhecido
3. verifique que Logs e Diagnósticos mostram o resultado esperado

---

## Modelo de Responsabilidade por Time

| Área | Responsável típico |
|------|-------------------|
| Taxonomia de campanha (UTMs, nomes de campanha) | Marketing / Analytics |
| Configuração, mapeamento de campos, wiring de consentimento | Agência / Implementador |
| Saúde do endpoint, comportamento da fila, falhas | Operações / Suporte |

Checklist de handoff:
1. documentar quais módulos estão ativos
2. documentar quais plugins de formulário ou fluxos de comércio estão no escopo
3. documentar se o GTM é injetado pelo ClickTrail ou por outra ferramenta
4. documentar a fonte de verdade para consentimento
5. documentar se a entrega server-side está ativa e quem é dono do endpoint

---

## Onde Ver o Valor

Se a implementação está funcionando, o time deve ver valor em pelo menos um destes lugares:

- registros de formulários contêm contexto de atribuição
- pedidos WooCommerce contêm contexto de atribuição
- leads chegam no CRM com campos UTM preenchidos
- `window.dataLayer` recebe eventos do ClickTrail
- Logs e Diagnósticos mostram atividade de entrega server-side
- jornadas entre domínios param de resetar a atribuição de origem

---

## Limitações em Checkouts Externos

Decoração de links e continuidade por token funcionam entre domínios que você controla e listou em **Captura → Decoração de Links → Domínios permitidos**. Elas não cobrem provedores de pagamento externos.

### O que não pode ser decorado

Os seguintes provedores processam pagamentos no domínio deles. O ClickTrail não tem mecanismo para injetar parâmetros nessas páginas:

- **Stripe Checkout** (`checkout.stripe.com`)
- **PayPal** (`paypal.com`)
- **Mollie** (`checkout.mollie.com`)
- **Square** (`squareup.com`)
- Qualquer outra página de pagamento hospedada que você não controla

### O que acontece na prática

A atribuição sobrevive a esses redirecionamentos **apenas se** o cookie de atribuição já tiver sido gravado antes de o usuário chegar ao provedor de pagamento. Na volta para sua página de confirmação, o ClickTrail lê o cookie e anexa a atribuição ao pedido normalmente.

O risco é estreito: um usuário que chega à sua página de checkout **sem** UTMs e sem cookie anterior gera um pedido sem atribuição. Isso é uma limitação conhecida de atribuição baseada em cookie em fluxos entre domínios — não é um bug do ClickTrail.

### Como reduzir a lacuna

1. Certifique-se de que a captura de atribuição está ativa e dispara cedo (antes do carregamento da página de checkout).
2. Não dependa de decoração de link para carregar atribuição pelo provedor de pagamento — ela será removida.
3. Se o seu funil é `site de marketing → checkout hospedado → URL de retorno`, teste que a URL de retorno recebe o cookie corretamente.
4. Para o Stripe, considere usar o campo `client_reference_id` do próprio Stripe para correlacionar a sessão do provedor ao visitor ID do ClickTrail — isso é uma integração manual, não nativa do ClickTrail.

### O aviso no checklist

Quando a decoração de links está ativa e nenhum domínio permitido está listado, o ClickTrail exibe um status **warn** no Setup Checklist (Configurações → Setup). Isso significa que a decoração está configurada mas não vai disparar. Adicione os domínios de destino para limpar o aviso.

---

## Erros Comuns

- ativar injeção de GTM quando o GTM já carrega por outra ferramenta
- ativar entrega server-side antes de o endpoint estar pronto
- esperar que Gravity Forms ou WPForms armazenem atribuição sem adicionar os campos ocultos `ct_*` manualmente
- tratar eventos de navegador e entrega server-side como o mesmo toggle
- ativar modo de consentimento sem validar o fluxo real de integração com o CMP

---

## Próximos Documentos

- referência de integrações e providers: [`INTEGRATIONS.md`](../reference/INTEGRATIONS.md)
- mapeamento de opções e configurações: [`SETTINGS-AND-ADMIN.md`](SETTINGS-AND-ADMIN.md)
- operações e troubleshooting: [`OPERATIONS-RUNBOOK.md`](OPERATIONS-RUNBOOK.md)
- rotas REST e modelo de autenticação: [`REST-API.md`](../reference/REST-API.md)
