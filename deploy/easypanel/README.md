# Deploy no Hostinger VPS (Easypanel + Traefik)

VPS **srv1283136** (`76.13.80.103`) roda **Ubuntu 24.04 com Easypanel** — não use o “Docker Manager” da API Hostinger (só funciona em templates Docker puros). Tudo passa pelo painel: [http://76.13.80.103:3000/](http://76.13.80.103:3000/).

Documentação Easypanel: [App](https://easypanel.io/docs/services/app), [Compose](https://easypanel.io/docs/services/compose), [Domínios / Traefik](https://easypanel.io/docs/services/app#domains), [GitHub](https://easypanel.io/docs/code-sources/github).

## Arquitetura (projeto `sorio`)

| Serviço Easypanel | Tipo | Domínio público | Rede |
|-------------------|------|-----------------|------|
| `casamento-gestao-postgres` | Compose | **nenhum** | Postgres só interno |
| `casamento-gestao-api` | App (Dockerfile raiz) | `casamento-gestao-api.sorio.io` → porta **8000** | Laravel + Octane |
| `casamento-gestao` | App (nginx proxy) | `casamento-gestao.sorio.io` → porta **80** | Proxy → `casamento-gestao-api:8000` |

O app é **Laravel + Inertia** (monólito). O “front” é um nginx que repassa tudo para a API; a subdomain `casamento-gestao-api` aponta direto para o mesmo backend (útil para MCP/OAuth e clientes que chamam API).

**Não publique portas** (`ports:`) nos compose — só domínios no Easypanel (HTTPS via Traefik/Let’s Encrypt).

## 1. DNS (`sorio.io`)

Apontar para **`76.13.80.103`** (e AAAA `2a02:4780:66:e2a8::1` se usar IPv6):

| Registro | Tipo | Nome |
|----------|------|------|
| A / AAAA | `@` ou subdomínio | `casamento-gestao` |
| A / AAAA | | `casamento-gestao-api` |

(O nome `casamento-gestao-postgres` é **hostname interno** do serviço Postgres, não precisa de registro DNS público.)

## 2. Projeto Easypanel `sorio`

1. **Create project** → nome `sorio`.
2. **Settings → GitHub** → token com `repo` (+ `admin:repo_hook` se quiser auto-deploy).

### 2.1 `casamento-gestao-postgres`

1. **New service → Compose** → nome `casamento-gestao-postgres`.
2. **Source → Git** → `https://github.com/artubss/gestao-familia`, branch `master`, build path `/deploy/easypanel/casamento-gestao-postgres`, arquivo `docker-compose.yml`.
3. **Environment** (exemplo — troque a senha):

```env
POSTGRES_DB=gestao_familia
POSTGRES_USER=gestao
POSTGRES_PASSWORD=<senha-forte-unica>
```

4. **Deploy**. Sem domínio. Anote o hostname interno: **`casamento-gestao-postgres`** (nome do serviço no projeto).

### 2.2 `casamento-gestao-api`

1. **New service → App** → nome `casamento-gestao-api`.
2. **Source → GitHub** → `artubss/gestao-familia`, branch `master`, build path `/`.
3. **Build → Dockerfile** (raiz do repo).
4. **Domains**:
   - Host: `casamento-gestao-api.sorio.io`, HTTPS on, target port **8000**, marque como primary se quiser.
5. **Environment** — copie de [`easypanel.env.example`](easypanel.env.example) e preencha `DB_PASSWORD`, `INITIAL_ADMIN_*`, etc.
6. **Storage** (recomendado): volume em `/app/storage` (uploads + `app.key`).
7. **Deploy** — no boot roda migrations + `InitialAdminSeeder` se `INITIAL_ADMIN_EMAIL` / `INITIAL_ADMIN_PASSWORD` estiverem definidos.

### 2.3 `casamento-gestao` (front / proxy)

1. **New service → App** → nome `casamento-gestao`.
2. **Source → GitHub** → mesmo repo, build path **`/deploy/easypanel/casamento-gestao`**, Dockerfile local.
3. **Domains**: `casamento-gestao.sorio.io`, target port **80**, HTTPS.
4. **Deploy** (depende da API já estar no ar na rede interna).

Ordem sugerida: **postgres → api → front**.

## 3. Login no app

Com as variáveis `INITIAL_ADMIN_*` do exemplo:

- E-mail: `contato@sorio.io`
- Senha: a definida em `INITIAL_ADMIN_PASSWORD` (login **“Usar senha”** / rota temporária de password, ou peça código por e-mail se configurar SMTP).

Configure **MAIL_*** no serviço API para códigos por e-mail em produção.

## 4. Atualizações

Push em `master` → **Deploy** no serviço API (e front se mudar nginx). Ou ative **GitHub auto-deploy** no Easypanel.

## 5. Referência Hostinger

- VPS listado na API: id **1283136**, IP **76.13.80.103**.
- Template atual: **Easypanel** — gerenciamento via painel, não via `vps_docker_create` da Hostinger.

## Segurança

- Nunca commite `.env` com senhas. Use só variáveis no Easypanel.
- Rotacione a senha que foi exposta no chat e atualize `INITIAL_ADMIN_PASSWORD` + `POSTGRES_PASSWORD`.
