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
- O template de PR foi preenchido com informacao relevante?
- Sao necessarios screenshots ou gifs para mudancas no admin?
- A mudanca criou ou removeu algum hotspot de manutencao que precisa aparecer em `CODE-QUALITY.md`?
- Os links de redirect e os caminhos de docs movidas continuam validos?
