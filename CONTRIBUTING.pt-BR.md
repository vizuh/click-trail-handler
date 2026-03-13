# Contribuindo com o ClickTrail

O ClickTrail tem tres audiencias principais de documentacao:

- visitantes e avaliadores no GitHub
- contribuidores e revisores
- agentes de IA e automacao

Antes de alterar codigo ou docs, encontre a referencia canonica da area afetada.

## Comece Aqui

1. Leia [README.md](README.md) para ver os pontos de entrada do repositorio.
2. Use [docs/README.md](docs/README.md) para encontrar a documentacao tecnica correta.
3. Consulte [.github/PULL_REQUEST_TEMPLATE.md](.github/PULL_REQUEST_TEMPLATE.md) antes de abrir um PR.

## Fluxo Local

1. Trabalhe a partir do estado atual do plugin, nao de docs antigas ou screenshots desatualizados.
2. Prefira mudancas pequenas, revisaveis e com intencao clara.
3. Mantenha a copy alinhada entre `README.en.md`, `README.pt-BR.md` e `readme.txt` quando a mensagem do produto mudar.
4. Atualize `changelog.txt` quando a mudanca merecer nota de release.

## Setup Local e Checks

Pre-requisitos:

- PHP `8.1+`
- Composer
- npm
- uma instalacao local do WordPress onde este repositorio esteja disponivel como plugin

Bootstrap recomendado:

1. Clone ou crie um symlink do repositorio dentro de `wp-content/plugins/` no seu WordPress local.
2. Execute `composer install` na raiz do repositorio para instalar os padroes de codigo e as ferramentas de contribuicao.
3. Ative o plugin no WordPress e confirme que as telas do admin abrem sem erros fatais.
4. Use `docs/README.md` para localizar a documentacao canonica do subsistema que voce pretende alterar antes de editar codigo.

Checks recomendados antes de abrir um PR:

- Execute `composer phpcs` como baseline de padrao de codigo do repositorio.
- Valide manualmente o fluxo afetado no WordPress, porque o repositorio ainda nao publica uma suite automatizada de testes PHP ou JS.
- Execute `npm run make-zip` quando a mudanca afetar empacotamento, preparacao de release ou quando voce quiser validar o build distribuivel do plugin. Esse comando encapsula `tools/release/make-zip.ps1`.

Exemplos de validacao manual:

- mudanca de atribuicao ou consentimento: visite uma URL com UTMs, confirme a captura de atribuicao e verifique o comportamento condicionado por consentimento
- mudanca em integracao de formulario: envie um formulario suportado e confirme que os campos de atribuicao mapeados ou os logs de evento estao corretos
- mudanca na entrega server-side: inspecione o comportamento da fila, as tentativas de retry e os logs/diagnosticos do adapter ou endpoint afetado

## Expectativas Para Pull Requests

- Explique o problema e a abordagem escolhida.
- Destaque impactos de compatibilidade, migracao ou comportamento em runtime.
- Inclua screenshots para mudancas no admin.
- Informe o que foi testado e o que nao foi possivel testar.
- Atualize a documentacao no mesmo PR quando a verdade do repositorio mudar.

## Matriz de Atualizacao de Docs

- Mudancas de admin ou settings: atualize [docs/guides/SETTINGS-AND-ADMIN.md](docs/guides/SETTINGS-AND-ADMIN.md) e qualquer copy afetada nos readmes.
- Mudancas de API ou webhooks: atualize [docs/reference/REST-API.md](docs/reference/REST-API.md) e [docs/reference/HOOKS-REFERENCE.md](docs/reference/HOOKS-REFERENCE.md).
- Mudancas de storage, cookies, fila ou retencao: atualize [docs/architecture/DATA-MODEL.md](docs/architecture/DATA-MODEL.md), [docs/guides/SECURITY-PRIVACY.md](docs/guides/SECURITY-PRIVACY.md) e [docs/guides/OPERATIONS-RUNBOOK.md](docs/guides/OPERATIONS-RUNBOOK.md).
- Mudancas de integracao: atualize [docs/reference/INTEGRATIONS.md](docs/reference/INTEGRATIONS.md) e os readmes do produto quando houver impacto para o usuario.
- Mudancas de arquitetura ou bootstrap: atualize [docs/architecture/PLUGIN-OVERVIEW.md](docs/architecture/PLUGIN-OVERVIEW.md) e [docs/architecture/CODE-MAP.md](docs/architecture/CODE-MAP.md).
- Mudancas de qualidade, limpeza ou codigo morto: atualize [docs/guides/CODE-QUALITY.md](docs/guides/CODE-QUALITY.md) quando a postura de manutencao do repositorio mudar.

## Regras de Fonte de Verdade

- Posicionamento do produto fica em `README.en.md`, `README.pt-BR.md` e `readme.txt`.
- IA do admin fica em `docs/guides/SETTINGS-AND-ADMIN.md`.
- Rotas e autenticacao ficam em `docs/reference/REST-API.md`.
- Storage e fila ficam em `docs/architecture/DATA-MODEL.md`.
- Verdade sobre integracoes suportadas fica em `docs/reference/INTEGRATIONS.md`.

## Checklist Pratico de Revisao

- O codigo bate com a documentacao atual?
- Um contribuidor novo consegue seguir o setup e os checks acima sem adivinhar pre-requisitos escondidos?
- O template de PR foi preenchido com informacao relevante?
- Sao necessarios screenshots ou gifs para mudancas no admin?
- A mudanca criou ou removeu algum hotspot de manutencao que precisa aparecer em `CODE-QUALITY.md`?
- Os links de redirect e os caminhos de docs movidas continuam validos?
