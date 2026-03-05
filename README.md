<div align="center">

![Vizuh Logo](assets/vizuh-logo.png)

# ClickTrail – UTM, Click ID & Ad Tracking (with Consent)

[![WordPress](https://img.shields.io/badge/WordPress-6.5%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-purple.svg)](https://php.net/)
[![License](https://img.shields.io/badge/License-GPLv2%2B-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Version](https://img.shields.io/badge/Version-1.3.2-orange.svg)](https://github.com/vizuh/click-trail)

**Stop losing campaign data. See what actually converts. Make better marketing decisions.**

[🇧🇷 Português](#português) • [🇺🇸 English](#english)

---

</div>

## English

### 🎯 What is ClickTrail?

Most WordPress sites **lose campaign data** the moment a visitor clicks away from the landing page. If they don't convert immediately, your UTM/click ID context is gone forever—leaving you with "Direct / None" conversions and a massive reporting blind spot.

**ClickTrail fixes that.**

It captures **first-touch and last-touch UTMs + click IDs** (gclid, fbclid, ttclid, msclkid, and more), stores them in first-party cookies (with consent), and **automatically injects that attribution into every form submission and WooCommerce order**—even when the buyer journey takes multiple pages or multiple sessions.

**The result?** You can finally prove which campaigns drive real conversions, cut wasted ad spend, and make marketing decisions based on actual data instead of guesswork.

Plus, the built-in consent banner with **Google Consent Mode v2** keeps you GDPR-compliant while preserving the attribution signals you need for GA4, Meta, and other platforms.

### ✨ Key Features

#### 🎯 **Marketing Attribution**
- **Multi-Touch Attribution**: Captures both first-touch and last-touch UTM parameters and click IDs
- **90-Day Cookie Persistence**: Maintains attribution data for up to 90 days
- **Automatic Form Integration**: Seamlessly injects attribution data into Contact Form 7, Fluent Forms, and Gravity Forms
- **WooCommerce Attribution**: Automatically saves attribution metadata to orders at checkout
- **Cache Resilience**: Client-side field injection ensures attribution works even on fully cached sites (WP Rocket, Cloudflare)
- **Cross-Domain Tracking**: Safe link decoration to pass attribution to subdomains and allowed external sites
- **Advanced Bot Protection**: Prevents attribution pollution from crawlers and headless browsers
- **Site Health & Diagnostics**: Proactive alerts for caching conflicts and cookie-blocking issues

#### 📈 **Advanced Event Tracking**
- **Client-Side Events**: Automatically tracks Site Searches, File Downloads, **Scroll Depth (25/50/75/90%)** using GTM's built-in variables, and **User Engagement** (10s/30s/1m/2m/5m) with descriptive engagement levels.
- **Server-Side Events**: Tracks User Logins, User Signups, and Comments, pushing them to the dataLayer.
- **GA4-Ready**: All events push to dataLayer in GA4-compatible format for easy GTM integration.

#### 🔒 **Privacy & Consent**
- **Built-in Consent Banner**: Customizable consent management interface
- **Google Consent Mode**: Full integration with Google Consent Mode v2
- **Flexible Consent Rules**:
  - **Strict Mode**: Everything denied by default
  - **Relaxed Mode**: Everything granted by default
  - **Geo-based Mode**: Custom rules for EU/UK/CH vs. rest of world
- **GDPR Ready**: Designed with privacy regulations in mind
- **Localization**: Native support for English and Portuguese (Brazil)

#### 📊 **Multi-Platform Click ID Support**
Captures click IDs from all major advertising platforms:
- **Google**: `gclid`, `wbraid`, `gbraid`
- **Meta/Facebook**: `fbclid`
- **TikTok**: `ttclid`
- **Microsoft**: `msclkid`
- **Twitter**: `twclid`
- **LinkedIn**: `li_fat_id`
- **Snapchat**: `ScCid`
- **Pinterest**: `epik`

#### 🛒 **WooCommerce Deep Integration**
- **Source Column**: New admin column showing first-touch attribution at a glance
- **Attribution Meta Box**: Complete first-touch and last-touch data on order pages
- **GA4 Purchase Events**: Enriched, GA4-ready purchase events with campaign data and line items
- **Duplicate Prevention**: Prevents duplicate events on page refresh

#### 💬 **WhatsApp Support**
- Supports attribution append on WhatsApp links (`wa.me`, `whatsapp.com`, `api.whatsapp.com`)
- Keeps messaging attribution continuity without click-event logging
- Works with optional encoded context in prefilled messages

### 🚀 Installation

1. Download the plugin from the [releases page](https://github.com/vizuh/click-trail/releases)
2. Upload to `/wp-content/plugins/click-trail-handler/` directory
3. Activate through the WordPress 'Plugins' menu
4. Navigate to **ClickTrail** in the admin menu to configure settings

### ⚙️ Configuration

Access the **Attribution & Consent Settings** page from the ClickTrail admin menu:

- **Attribution Toggle**: Enable/disable attribution capture
- **Cookie Duration**: Set persistence time (default: 90 days)
- **Consent Requirements**: Choose whether to require user consent
- **Consent Mode**: Select Strict, Relaxed, or Geo-based mode
- **Integration Settings**: Configure form and WooCommerce integrations

### 🧪 Testing Your Setup

1. **Test Attribution Capture**: Visit your site with UTM parameters (e.g., `?utm_source=test&utm_medium=cpc`)
2. **Verify Cookie Storage**: Check browser cookies for `ct_attribution` or `attribution`
3. **Test Form Submission**: Submit a form and verify attribution data is captured
4. **WooCommerce Test**: Complete a test order and check the "Source" column and order meta

### 📖 Documentation

- Local technical docs (code-grounded): [`docs/README.md`](docs/README.md)
- Public docs: [vizuh.com/clicktrail-docs](https://vizuh.com)

### 🤝 Support

Need help? Contact us at [support@vizuh.com](mailto:support@vizuh.com)

### 📄 License

This plugin is licensed under the [GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html).

### 🌟 About Vizuh

**Vizuh** develops cutting-edge digital solutions for WordPress and WooCommerce. We specialize in marketing attribution, analytics, and privacy-compliant tracking solutions.

Visit us at [vizuh.com](https://vizuh.com)

---

## Português

### 🎯 O que é o ClickTrail?

**ClickTrail** é um plugin WordPress completo que conecta **conformidade com privacidade do usuário** e **atribuição de marketing precisa**. Combina gerenciamento robusto de consentimento com rastreamento poderoso de atribuição para ajudá-lo a entender quais campanhas geram resultados—respeitando a privacidade do usuário.

O plugin captura UTMs de primeiro e último toque e click IDs, armazena em cookies (com consentimento do usuário quando necessário), e anexa automaticamente esses dados às suas entradas de formulários e pedidos WooCommerce. O banner de consentimento integrado com Google Consent Mode garante que seu rastreamento permaneça em conformidade com GDPR e outras regulamentações de privacidade.

### ✨ Recursos Principais

#### 🎯 **Atribuição de Marketing**
- **Atribuição Multi-Touch**: Captura parâmetros UTM e click IDs de primeiro e último toque
- **Persistência de 90 Dias**: Mantém dados de atribuição por até 90 dias
- **Integração Automática com Formulários**: Injeta dados de atribuição em Contact Form 7, Fluent Forms e Gravity Forms
- **Atribuição WooCommerce**: Salva automaticamente metadados de atribuição em pedidos no checkout
- **Resiliência a Cache**: Injeção de campos via JS garante que a atribuição funcione mesmo em sites com cache agressivo (WP Rocket, Cloudflare)
- **Rastreamento Transdomínio**: Decoração segura de links para passar atribuição para subdomínios e sites externos permitidos
- **Proteção Avançada contra Bots**: Previne poluição de dados por robôs e navegadores headless
- **Saúde do Site e Diagnóstico**: Alertas proativos para conflitos de cache e bloqueio de cookies

#### 📈 **Rastreamento de Eventos Avançado**
- **Eventos Client-Side**: Rastreia automaticamente Buscas no Site, Downloads de Arquivos, **Profundidade de Rolagem (25/50/75/90%)** usando variáveis integradas do GTM, e **Engajamento do Usuário** (10s/30s/1m/2m/5m) com níveis de engajamento descritivos.
- **Eventos Server-Side**: Rastreia Login de Usuário, Cadastro de Usuário e Comentários, enviando-os para o dataLayer.
- **Pronto para GA4**: Todos os eventos são enviados para dataLayer em formato compatível com GA4 para fácil integração com GTM.

#### 🔒 **Privacidade & Consentimento**
- **Banner de Consentimento Integrado**: Interface personalizável de gerenciamento de consentimento
- **Google Consent Mode**: Integração completa com Google Consent Mode v2
- **Regras de Consentimento Flexíveis**:
  - **Modo Restrito**: Tudo negado por padrão
  - **Modo Relaxado**: Tudo concedido por padrão
  - **Modo Geográfico**: Regras customizadas para visitantes da UE/UK/CH vs. resto do mundo
- **Compatível com GDPR**: Desenvolvido pensando em regulamentações de privacidade

#### 📊 **Suporte Multi-Plataforma para Click IDs**
Captura click IDs de todas as principais plataformas de publicidade:
- **Google**: `gclid`, `wbraid`, `gbraid`
- **Meta/Facebook**: `fbclid`
- **TikTok**: `ttclid`
- **Microsoft**: `msclkid`
- **Twitter**: `twclid`
- **LinkedIn**: `li_fat_id`
- **Snapchat**: `ScCid`
- **Pinterest**: `epik`

#### 🛒 **Integração Profunda com WooCommerce**
- **Coluna de Origem**: Nova coluna administrativa mostrando atribuição de primeiro toque
- **Meta Box de Atribuição**: Dados completos de primeiro e último toque nas páginas de pedidos
- **Eventos de Compra GA4**: Eventos de compra enriquecidos e prontos para GA4 com dados de campanha e itens de linha
- **Prevenção de Duplicatas**: Previne eventos duplicados ao atualizar a página

#### 💬 **Suporte ao WhatsApp**
- Suporta anexar contexto de atribuição em links do WhatsApp (`wa.me`, `whatsapp.com`, `api.whatsapp.com`)
- Mantém continuidade de atribuição sem registrar eventos de clique
- Funciona com contexto codificado em mensagens pré-preenchidas

### 🚀 Installation

1. Download the plugin from the [releases page](https://github.com/vizuh/click-trail/releases)
2. Upload to `/wp-content/plugins/click-trail-handler/` directory
3. Activate through the WordPress 'Plugins' menu
4. Navigate to **ClickTrail** in the admin menu to configure settings

### ⚙️ Configuration

Access the **Attribution & Consent Settings** page from the ClickTrail admin menu:

- **Attribution Toggle**: Enable/disable attribution capture
- **Cookie Duration**: Set persistence time (default: 90 days)
- **Consent Requirements**: Choose whether to require user consent
- **Consent Mode**: Select Strict, Relaxed, or Geo-based mode
- **Integration Settings**: Configure form and WooCommerce integrations

### 🧪 Testing Your Setup

1. **Test Attribution Capture**: Visit your site with UTM parameters (e.g., `?utm_source=test&utm_medium=cpc`)
2. **Verify Cookie Storage**: Check browser cookies for `ct_attribution` or `attribution`
3. **Test Form Submission**: Submit a form and verify attribution data is captured
4. **WooCommerce Test**: Complete a test order and check the "Source" column and order meta

### 📖 Documentation

For detailed documentation, visit [vizuh.com/clicktrail-docs](https://vizuh.com)

### 🤝 Support

Need help? Contact us at [support@vizuh.com](mailto:support@vizuh.com)

### 📄 License

This plugin is licensed under the [GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html).

### 🌟 About Vizuh

**Vizuh** develops cutting-edge digital solutions for WordPress and WooCommerce. We specialize in marketing attribution, analytics, and privacy-compliant tracking solutions.

Visit us at [vizuh.com](https://vizuh.com)

---

<div align="center">

![Vizuh Logo](assets/vizuh-logo.png)

**Made with ❤️ by [Vizuh](https://vizuh.com)**

[![Website](https://img.shields.io/badge/Website-vizuh.com-orange)](https://vizuh.com)
[![GitHub](https://img.shields.io/badge/GitHub-vizuh-black)](https://github.com/vizuh)
[![Support](https://img.shields.io/badge/Support-support%40vizuh.com-blue)](mailto:support@vizuh.com)

</div>

