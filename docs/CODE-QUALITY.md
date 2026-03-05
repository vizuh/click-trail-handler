# Code Quality Policy

## Typing Policy

ClickTrail currently runs in a single, consistent typing mode:

- `declare(strict_types=1);` is not allowed in runtime plugin files.
- Mixed strict/non-strict mode is forbidden.

Reason: mixed mode causes inconsistent scalar coercion semantics across files and leads to subtle runtime bugs.

## Enforcement

Run the typing policy check:

```bash
php tools/qa/check-typing-policy.php
```

Run PHPCS:

```bash
phpcs --standard=phpcs.xml.dist
```

Auto-fix formatting where possible:

```bash
phpcbf --standard=phpcs.xml.dist
```
