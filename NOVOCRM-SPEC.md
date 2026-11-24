# NOVOCRM — Nova interface do CRM DNB (inspirada no Vértice CRM)

> **Para o agente (Claude Code):** leia este documento inteiro antes de começar. Execute **uma fase por vez**, na ordem, e pare ao final de cada fase para revisão. Não pule regras da seção 2.

## 0. Contexto em uma frase

O repositório `deulaag/crmdnb` (PHP 8.2 + MySQL, cPanel) já funciona e tem toda a regra de negócio. Vamos criar a pasta **`/novocrm`** dentro desse repositório com uma **interface nova, inspirada no Vértice CRM**, usando **o mesmo banco, o mesmo login e as mesmas funções PHP**. O CRM atual, na raiz, continua funcionando sem nenhuma alteração.

- Referência visual: https://vertice.afcode.com.br/#/ (demonstração; usar só como referência de layout e UX, **sem copiar código, textos ou marca**).
- Stack do novo: **PHP 8.2 + HTMX + JS mínimo** (sem React, sem build, sem Node em produção).
- Objetivo de UX: qualquer vendedor deve conseguir usar **sem treinamento**: poucas telas, uma ação principal por tela, tudo a no máximo 2 cliques.

---

## 1. Arquitetura

```
/                       ← CRM atual (NÃO ALTERAR)
├── includes/           ← bootstrap.php, auth.php, security.php, functions.php (REUTILIZAR)
├── config/             ← database.local.php (REUTILIZAR, mesmo banco)
├── storage/
└── novocrm/            ← TUDO que é novo fica aqui
    ├── _app.php            # bootstrap do novo: require ../includes/auth.php + helpers de UI
    ├── _layout.php         # nc_header(), nc_footer(): sidebar, topo, busca, toasts, modal
    ├── login.php           # só redireciona para /login.php?redirect_to=/novocrm/...
    ├── index.php           # redireciona para painel.php
    ├── painel.php
    ├── oportunidades.php   # kanban
    ├── oportunidade.php    # ficha (abre em painel lateral via HTMX)
    ├── clientes.php        # une clientes.php + empresas.php da raiz
    ├── cliente.php         # ficha do cliente
    ├── pedidos.php
    ├── visitas.php         # visitas + agenda/cronograma
    ├── comissoes.php
    ├── configuracoes.php   # aparência + atalhos para as configurações da raiz
    ├── acoes/              # endpoints POST chamados por HTMX (retornam fragmentos HTML)
    ├── partials/           # fragmentos reutilizáveis (card de kanban, linha de tabela, KPI…)
    ├── migrations/         # SQL ADITIVO numerado (001_*.sql, 002_*.sql…)
    └── assets/
        ├── css/novocrm.css
        ├── js/novocrm.js
        └── vendor/         # htmx.min.js e Sortable.min.js SALVOS LOCALMENTE (sem CDN)
```

### Como o novo reutiliza o atual
- `novocrm/_app.php` faz `require_once __DIR__ . '/../includes/auth.php';` e depois `$user = require_auth();`.
- A sessão já é compartilhada: o cookie `DNBCRMSESSID` usa `path=/`. Quem está logado na raiz já está logado no `/novocrm`.
- **Login:** `security_login_url()` gera `login.php?redirect_to=...` **relativo**, então em `/novocrm` ele cai em `/novocrm/login.php`. Esse arquivo deve **apenas redirecionar** para `/login.php?redirect_to=` mantendo o parâmetro. `safe_internal_redirect()` já aceita caminho absoluto como `/novocrm/painel.php`. Não duplicar a lógica de login, Turnstile ou throttle.
- Usar as funções existentes: `db()`, `e()`, `money()`, `csrf_token()`, `csrf_field()`, `verify_csrf()`, `flash()`, `pull_flash()`, `audit()`, `valid_month()`, `cnpja_lookup()`, `rd_crm_sync()`, `user_commission_policy()` etc.
- As **regras de negócio dos POST** hoje estão dentro das páginas da raiz (ex.: `orcamentos.php`, bloco `elseif ($action === 'stage')`). No novo, **copie a lógica** para `novocrm/acoes/*.php` mantendo **exatamente as mesmas validações, mensagens e chamadas de `audit()`**. Não altere os arquivos da raiz para extrair funções nesta etapa.

---

## 2. Regras obrigatórias (não negociáveis)

1. **Não editar nenhum arquivo fora de `/novocrm`**, exceto se uma fase disser explicitamente. Se achar necessário, **pare e pergunte**.
2. **Banco:** só alterações **aditivas** (nova tabela, ou nova coluna `NULL` / com `DEFAULT`). Nunca `DROP`, `RENAME`, `MODIFY` de tipo, nem `NOT NULL` em coluna existente. O CRM da raiz tem que continuar funcionando com o banco alterado. Cada mudança vira um arquivo em `novocrm/migrations/` e é aplicada por `novocrm/migrations/run.php` (somente admin, idempotente, registra em tabela `nc_migrations`).
3. **Nada automático duplicado:** o novo **não** chama `rd_sync_all()`, `rd_crm_sync()`, `process_quote_reminders()` nem `process_commercial_agenda_reminders()` automaticamente. Sincronizações e lembretes continuam sendo disparados só pela raiz. Botões manuais de "Sincronizar RD" no novo podem existir, chamando a mesma função, **somente para admin**.
4. **Segurança:** todo POST chama `verify_csrf()`; todo endpoint chama `require_auth()`; ações de admin usam `require_admin()`; toda saída passa por `e()`; SQL sempre com prepared statements. `verify_csrf()` lê **somente `$_POST['csrf_token']`**. Por isso, toda requisição HTMX deve mandar o token **no corpo**: com `csrf_field()` dentro do `<form>`, ou com `hx-vals='{"csrf_token":"..."}'` no `<body>` para valer em todos os `hx-post`. O drag-and-drop do kanban também envia `csrf_token` no corpo. **Não alterar `verify_csrf()`.** O erro 419 deve abrir o modal "Sessão expirada, atualize a página".
5. **Mesmas permissões da raiz:** onde a página original checa `$isAdmin` ou papel, o novo checa igual.
6. **Acesso controlado durante o desenvolvimento:** enquanto não houver liberação, `/novocrm` só abre para `role = admin` (constante `NC_PUBLIC = false` em `_app.php`). Os demais usuários são redirecionados para `/painel.php`.
7. **Deploy:** o workflow `deploy.yml` publica a pasta inteira a cada push na `main`. Trabalhe na branch **`novocrm`** e só faça merge quando a fase estiver aprovada.
8. **Sem dependências externas em tempo de execução:** sem CDN. htmx e SortableJS ficam em `novocrm/assets/vendor/`. As fontes Michroma e Lato podem continuar via Google Fonts, como na raiz.
9. **CI:** o workflow `quality.yml` roda `php -l` em todos os `.php` e `node --check` em JS listados. Ao criar `novocrm/assets/js/novocrm.js`, adicione-o ao `node --check` dos dois workflows (**única edição permitida fora de `/novocrm`**). Estenda `tests/smoke_test.php` só se for aditivo.
10. **Acessibilidade:** `:focus-visible` sempre visível, alvos de clique ≥ 44px, status nunca só por cor (cor + texto), `prefers-reduced-motion` respeitado, contraste AA.

---

## 3. Design (o que copiar do Vértice e como adaptar à DNB)

### 3.1 Estrutura da tela (igual ao Vértice)
- **Sidebar fixa à esquerda** (~240px), recolhível para só ícones (~64px) com botão e atalho `[`. Estado salvo em `localStorage` (com `try/catch`).
  - Topo: logo DNB (`/assets/dnb-logo.png` ou o branding configurado via `branding_logo_url()`).
  - Itens com ícone + texto, item ativo com fundo de destaque suave e barra lateral.
  - Rodapé da sidebar: nome do usuário, papel e "Sair" (`/logout.php`).
- **Barra superior:** breadcrumb ("DNB / Oportunidades") à esquerda; à direita, **Buscar ⌘K / Ctrl+K**, botão de atualizar e alternador de tema claro/escuro.
- **Cabeçalho de página:** linha pequena de contexto acima (ex.: "12 oportunidades abertas · R$ 1,2 mi"), **título grande**, e **um botão primário** à direita ("+ Nova oportunidade").
- **Abas de filtro** acima das listas (ex.: Todas | Abertas | Ganhas | Perdidas), com contagem.
- **Cards** com borda sutil, cantos arredondados (12px), muito espaço em branco.
- **Tabelas** limpas: cabeçalho pequeno em caixa-alta, linhas com hover, valores alinhados à direita em fonte tabular, ações no fim da linha.
- **Fichas (detalhe)** abrem em **painel lateral à direita** (drawer) via HTMX, sem sair da lista. URL atualizada com `hx-push-url` para poder compartilhar o link.
- **Feedback:** toasts no canto para sucesso; modal acessível para erro e para confirmar exclusão (manter a regra atual do design system: ação destrutiva sempre com confirmação modal).

### 3.2 Menu (sidebar)
| Item | Arquivo | Substitui na raiz |
|---|---|---|
| Painel | `painel.php` | `painel.php` |
| Oportunidades | `oportunidades.php` | `orcamentos.php` + `oportunidade.php` |
| Clientes | `clientes.php` | `clientes.php` + `empresas.php` |
| Pedidos | `pedidos.php` | `pedidos.php` |
| Visitas & Agenda | `visitas.php` | `visitas.php` + `cronograma.php` |
| Cadência | link para `/cadencia.php` (fase futura) | `cadencia.php` |
| Comissões | `comissoes.php` | `comissoes.php` |
| Configurações | `configuracoes.php` | Aparência nova + link para `/configuracoes.php` e `/usuarios.php` (admin) |

Itens ainda não migrados apontam para a página da raiz e mostram um selo pequeno "versão clássica".

### 3.3 Paleta e tipografia (DNB, não a do Vértice)
Use **CSS custom properties** e dois temas (`[data-theme="light"]` e `[data-theme="dark"]`).

| Token | Claro | Escuro |
|---|---|---|
| `--bg` | `#F4F7FA` | `#0E1622` |
| `--surface` | `#FFFFFF` | `#152132` |
| `--text` | `#142033` | `#E7EEF6` |
| `--text-muted` | `#5F6E7C` | `#93A4B5` |
| `--border` | `#D9E2EA` | `#25344A` |
| `--primary` (botão principal, item ativo) | `#003060` | `#A4C8FF` |
| `--primary-soft` | `#E3EDF9` | `#1C3352` |
| `--accent` | `#A4C8FF` | `#003060` |
| `--success` | `#177245` | `#4CC38A` |
| `--warning` | `#A15C00` | `#F0B35A` |
| `--danger` | `#B42318` | `#F97066` |

- Títulos e marca: **Michroma**. Texto, tabelas e formulários: **Lato**. Corpo 15px, auxiliar ≥ 12px, line-height 1.5.
- Números (valores, números de oportunidade/pedido): `font-variant-numeric: tabular-nums`.
- Tela de **Aparência** (em `configuracoes.php`): tema Claro / Escuro / Seguir sistema, densidade de tabela Confortável / Compacta, sidebar Expandida / Recolhida. Salvar em `localStorage` (por usuário/navegador). Não precisa de seletor de cor de destaque nesta versão.

### 3.4 Componentes (`partials/` e `novocrm.css`)
`nc-kpi` (rótulo, valor grande, linha de apoio, sparkline SVG opcional), `nc-tabs`, `nc-table`, `nc-badge` (status: cor + texto), `nc-progress` (barra), `nc-kanban` / `nc-kanban-col` / `nc-deal-card`, `nc-drawer`, `nc-modal`, `nc-toast`, `nc-empty` (estado vazio com texto e botão de ação), `nc-search` (paleta ⌘K).

---

## 4. Telas: o que cada uma mostra e de onde vêm os dados

### 4.1 Painel (`painel.php`)
- Filtro de período no topo: **Mês atual | 30 dias | 90 dias | mês escolhido** (o atual só tem mês).
- 4 KPIs, com as mesmas consultas do `painel.php` da raiz: **Oportunidades no período**, **Pipeline aberto** (qtd + R$), **Faturado** (`orders.invoiced=1` por `invoice_date`), **Visitas**.
- Gráfico de linha **Faturamento por semana**, período atual × anterior (SVG gerado no servidor, sem biblioteca).
- Gráfico de barras **Pipeline aberto por linha de produto** (`product_lines`), com rótulo **Agenciado × Revenda** (`orders.modality` / `product_lines.is_agency`).
- Card **Funis**: um bloco por funil (`funnels`), com barra por etapa (`funnel_stages`) mostrando qtd e R$. Link "Abrir kanban".
- Card **Últimas oportunidades** (8 linhas) e **Próximos compromissos** (`agenda` com `status IN ('sugerido','agendado','confirmado')`, ordem por `event_date`).
- Para `role = comercial`, filtrar por `seller_user_id = usuário`; gestor e admin veem tudo, com seletor de vendedor.

### 4.2 Oportunidades: kanban (`oportunidades.php`)
- **Seletor de funil** no topo, em abas: Equipamentos | Serviços e Locação | Peças e Revenda (de `funnels`, ordem `sort_order`).
- Colunas = `funnel_stages` do funil, com contagem e soma em R$ no cabeçalho da coluna.
- Card: cliente, nº (`number`), linha de produto, valor, vendedor (iniciais), selo **"Orçamento pendente"** se `quote_required=1 AND quote_completed_at IS NULL`, dias parado na etapa (via `updated_at`).
- **Arrastar e soltar** entre colunas (SortableJS) → `POST acoes/oportunidade-etapa.php` com `id`, `stage_id`, CSRF. Copiar a lógica do bloco `$action === 'stage'` de `orcamentos.php`: validar que a etapa pertence ao funil, `UPDATE`, `audit($pdo, $userId, 'update_stage', 'opportunity', $id)`. Em erro, o card volta para a coluna original e aparece o modal de erro.
- Ordenação dentro da coluna: coluna nova `opportunities.position INT NULL` (migração 001). Se `NULL`, ordenar por `updated_at DESC`.
- Rodapé de cada coluna ou área fixa no fim: **Ganha** e **Perdida** como zonas de soltar.
  - Soltar em **Ganha**: mesma regra de `status` de `orcamentos.php`/`oportunidade.php` (bloquear se orçamento obrigatório não concluído, com a mesma mensagem).
  - Soltar em **Perdida**: abre modal pedindo **motivo da perda** (obrigatório), mesma regra de `status_lost` em `oportunidade.php`.
- Alternador **Kanban | Lista**. A lista é tabela com filtros: vendedor, linha, status, mês, busca.
- Botão **+ Nova oportunidade** abre o drawer com o formulário (mesmos campos e validações de criação de `orcamentos.php`, incluindo `generate_opportunity_number()`).
- Botões secundários em menu "Importar": Cotador (JSON), PDF do ERP, Relatório Salesforce. Nesta fase, eles **levam para `/orcamentos.php`** (versão clássica). Migrar depois.

### 4.3 Ficha da oportunidade (`oportunidade.php`, drawer)
- Cabeçalho: nº, cliente (link para ficha do cliente), valor, status (badge), etapa.
- Ações: **Concluir orçamento** (`quote_complete`), **Marcar ganha**, **Marcar perdida** (com motivo), **Reabrir**, todas com a mesma lógica de `oportunidade.php` da raiz.
- Abas internas: **Resumo** (campos), **Notas** (`opportunity_notes`, com formulário `add_note`), **Histórico** (`audit_logs` da entidade).

### 4.4 Clientes (`clientes.php` + `cliente.php`)
- **Uma tela só**, baseada em `companies` + `contacts`.
- Colunas: Empresa (nome + razão/alias), CNPJ formatado (`format_cnpj()`), Cidade/UF, Segmento (`industry`), Responsável (`owner_user_id`), Oportunidades abertas (qtd/R$), Último pedido, Status da carteira.
- Abas: **Todos | Ativos | Em risco | Inativos**. Coluna nova `companies.portfolio_status VARCHAR(20) NULL` (migração 002). Enquanto `NULL`, calcular: Ativo = pedido nos últimos 180 dias; Em risco = último pedido entre 181 e 365 dias; Inativo = sem pedido há mais de 365 dias ou nunca.
- Busca por nome, CNPJ (só dígitos via `cnpj_digits()`) e cidade.
- **+ Novo cliente** no drawer: campo CNPJ com botão **Consultar** → `cnpja_lookup()` preenche o formulário, como na raiz. Validar com `cnpj_is_valid()`.
- Ficha do cliente: dados cadastrais, contatos, oportunidades, pedidos, visitas e agenda daquele cliente.
- Botão **Sincronizar RD Station** só para admin (mesma ação `rd_crm_sync` da raiz).
- Observação: oportunidades, pedidos e visitas guardam `client_name` em texto e `company_id` opcional. Nas fichas, buscar por `company_id` **e** por `client_name` normalizado (`normalized_name()`). Não alterar dados existentes.

### 4.5 Pedidos (`pedidos.php`)
- Abas: **Todos | A faturar | Faturados**. Colunas: nº, data, cliente, linha, modalidade (Revenda/Agenciada), valor, comissão DNB, margem, status.
- **+ Novo pedido** no drawer, com os mesmos campos e validações de `create` em `pedidos.php`, mantendo o texto explicativo sobre a comissão.
- Ação **Faturar** (`invoice`) inline, com data.
- Opcional (migração 003): tabela `order_items` (`order_id`, `description`, `sku NULL`, `quantity`, `unit_price`, `subtotal`). Itens **opcionais**. Se houver itens, o total pode ser calculado, mas `orders.amount` continua sendo a fonte do valor.

### 4.6 Visitas & Agenda (`visitas.php`)
- Duas abas: **Agenda** (padrão) e **Visitas**.
- **Agenda:** lista por dia (Hoje, Amanhã, Esta semana, Atrasados) a partir de `agenda`. Cards com cliente, objetivo, tipo (compromisso, follow-up, retorno de visita) e status. Ações rápidas: Confirmar, Concluir, Cancelar (mesma lógica de `status` em `cronograma.php`). **+ Novo compromisso** no drawer (`create`).
- **Visitas:** tabela de `visits` com filtros. Status do lead (pendente, promovida, descartada) como badge. Ações **Promover a oportunidade** e **Descartar** (mesma lógica de `promote_visit`/`discard_visit` em `orcamentos.php`). **+ Nova visita** manual no drawer.
- "Importar PDF de visita (IA)" e "Importar JSON": nesta fase, levam para `/visitas.php` (clássico).

### 4.7 Comissões (`comissoes.php`)
- Cards por pessoa (como "Equipe & Comissões" do Vértice): iniciais, nome, política (`user_commission_policy()`), faturado no mês, comissão calculada, **meta e % atingido** com barra.
- Meta: coluna nova `users.monthly_target DECIMAL(15,2) NULL` (migração 004), editável só por admin/gestor. Sem meta, esconder a barra.
- Aba **Extrato**: tabela por pedido faturado no mês com a comissão de cada pessoa, com a **mesma fórmula do `comissoes.php` da raiz** (copiar, não reinventar).
- Seletor de mês.

### 4.8 Busca global (⌘K / Ctrl+K)
- Modal com campo único. Usar `hx-get="acoes/busca.php?q="` com `hx-trigger="input changed delay:250ms"`.
- Resultados agrupados: Clientes (nome/CNPJ), Oportunidades (nº/cliente), Pedidos (nº/cliente), Visitas (OS/cliente). Máximo de 5 por grupo. Navegação por setas e Enter.
- Respeitar o filtro por vendedor quando `role = comercial`.

---

## 5. Migrações (todas aditivas)

```sql
-- 001_opportunity_position.sql
ALTER TABLE opportunities ADD COLUMN position INT NULL;

-- 002_company_portfolio_status.sql
ALTER TABLE companies ADD COLUMN portfolio_status VARCHAR(20) NULL;

-- 003_order_items.sql
CREATE TABLE IF NOT EXISTS order_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  description VARCHAR(255) NOT NULL,
  sku VARCHAR(80) NULL,
  quantity DECIMAL(12,2) NOT NULL DEFAULT 1,
  unit_price DECIMAL(15,2) NOT NULL DEFAULT 0,
  subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 004_user_monthly_target.sql
ALTER TABLE users ADD COLUMN monthly_target DECIMAL(15,2) NULL;
```
`run.php` verifica se a coluna ou tabela já existe antes de aplicar (consultar `information_schema`) e registra em `nc_migrations(name, applied_at)`.

---

## 6. Fases e critérios de aceite

### Fase 0: Base
- Estrutura de pastas, `_app.php`, `_layout.php`, `login.php` (redirecionamento), `index.php`, `novocrm.css`, `novocrm.js`, vendor htmx e Sortable, `migrations/run.php`.
- Sidebar, topo, breadcrumb, tema claro/escuro, sidebar recolhível, toasts, modal, drawer, paleta ⌘K vazia.
- Trava `NC_PUBLIC = false` (só admin).
- Adicionar `novocrm/assets/js/novocrm.js` ao `node --check` dos workflows.
- **Aceite:** admin logado na raiz abre `/novocrm/` sem novo login; não-admin é redirecionado para `/painel.php`; deslogado vai para `/login.php` e volta para `/novocrm/...` após entrar; `php -l` sem erros; CRM da raiz intacto (`git diff --stat` só mostra `/novocrm` e workflows).

### Fase 1: Painel + Clientes
- Seções 4.1, 4.4 e busca ⌘K (4.8) para clientes. Migração 002.
- **Aceite:** KPIs do mês batem com os do `/painel.php` da raiz para o mesmo mês; consulta de CNPJ funciona; criar cliente pelo novo aparece em `/empresas.php` da raiz.

### Fase 2: Oportunidades
- Seções 4.2 e 4.3. Migração 001.
- **Aceite:** arrastar card muda a etapa e aparece igual em `/orcamentos.php`; "Ganha" bloqueia com orçamento pendente (mesma mensagem); "Perdida" exige motivo; `audit_logs` registra as mesmas ações que a raiz registraria.

### Fase 3: Pedidos + Visitas & Agenda
- Seções 4.5 e 4.6. Migração 003.
- **Aceite:** pedido criado e faturado no novo aparece igual na raiz e nas comissões; promover visita cria a oportunidade com o mesmo resultado da raiz.

### Fase 4: Comissões + Aparência + busca completa
- Seções 4.7, 3.3 (tela de Aparência) e 4.8 completa. Migração 004.
- **Aceite:** valores do extrato iguais aos do `/comissoes.php` da raiz para o mesmo mês.

### Fase 5: Liberação (só quando aprovado)
- `NC_PUBLIC = true`. Na raiz (**com autorização explícita**), adicionar link "Experimentar a nova versão" no topo.
- Depois de um período de uso: migrar importações por IA, Cadência e Configurações e, por último, decidir se `/novocrm` passa a ser a raiz.

---

## 7. Checklist de entrega de cada fase
- [ ] Nenhum arquivo fora de `/novocrm` alterado (exceto os permitidos).
- [ ] Todas as migrações aditivas e idempotentes.
- [ ] Todo POST com `verify_csrf()`; toda página/endpoint com `require_auth()`.
- [ ] Mesmas validações, mensagens e `audit()` da página original.
- [ ] Funciona em 1366px, 1920px e celular (sidebar vira menu sobreposto abaixo de 900px).
- [ ] Tema escuro conferido em todas as telas da fase.
- [ ] `php -l` e `node --check` sem erros; `tests/smoke_test.php` passando.
- [ ] Resumo do que foi feito + lista de arquivos criados + como testar.
