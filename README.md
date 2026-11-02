# Gestão Família

Aplicativo para **organizar a vida em família**: calendário compartilhado, tarefas, listas de compras, contatos, finanças do dia a dia e módulos opcionais — na web responsiva e preparado para mobile.

Este repositório é uma derivação do [FamilyWell](https://github.com/bvdr/familywell), com **português do Brasil como idioma padrão** da interface. O inglês e os demais idiomas continuam disponíveis em **Configurações → Idioma**.

## Idiomas

| Configuração | Valor padrão |
|--------------|--------------|
| `APP_LOCALE` | `pt` (português brasileiro) |
| `APP_FALLBACK_LOCALE` | `en` (textos ainda não traduzidos caem no inglês) |

As traduções ficam em `lang/pt/` e `lang/pt.json`, mescladas sobre o inglês (`lang/en/`). Novas chaves devem nascer em inglês e ganhar entrada correspondente em `pt` — o teste `tests/Feature/Localization/LocalizationTest.php` exige paridade de chaves.

## Requisitos

- PHP 8.3+, Composer, Node.js 20+
- SQLite (desenvolvimento) ou PostgreSQL (produção)
- Opcional: Docker — ver `docker-compose.yml`

## Instalação rápida (local)

```bash
cp .env.example .env
# SQLite: crie o arquivo do banco
# Windows (PowerShell): New-Item -ItemType File -Path database/database.sqlite -Force
touch database/database.sqlite   # Linux/macOS

composer install
php artisan key:generate
php artisan migrate
php artisan modules:migrate
npm install
npm run build
php artisan serve
```

Abra `http://localhost:8000`, cadastre-se com e-mail (código de 6 dígitos). Sem SMTP configurado, o código aparece nos logs.

## Docker

```bash
docker compose up -d
```

A aplicação sobe em `http://localhost:8000`. Detalhes em [docs/self-hosting.md](docs/self-hosting.md) e [docs/configuration.md](docs/configuration.md).

## Desenvolvimento

```bash
composer dev
```

Roda servidor, fila, Vite e (se configurado) Reverb.

Testes:

```bash
php artisan test
npm run test:viewports   # após build, com app seedada
```

## Licença

O código-base original usa **PolyForm Noncommercial License 1.0.0** — uso permitido para fins **não comerciais**. Veja [LICENSE](LICENSE) e o aviso de copyright do autor original.

## Créditos

- Projeto base: [bvdr/familywell](https://github.com/bvdr/familywell)
- Fork / adaptação: [artubss/gestao-familia](https://github.com/artubss/gestao-familia)
