# CRM DNB — Fase 2: UI comercial e relacionamentos

A Fase 2 torna visível o núcleo criado na Fase 1. Não conecta ainda o RD Station; OAuth, descoberta de funis/campos e sincronização pertencem às fases seguintes.

## Navegação

O grupo CRM da lateral passa a exibir:

- Painel
- Empresas
- Contatos
- Negociações
- Pedidos
- Visitas

Orçamentos deixa de ser um item isolado da lateral e passa a fazer parte do módulo Negociações, cuja subnavegação é:

- Leads / Pré-vendas
- Negociações
- Orçamentos

## Empresas

`empresas.php` oferece busca, revisão de duplicidades e cadastro.

`empresa.php` oferece visão 360:

- Resumo
- Contatos
- Negociações
- Orçamentos
- Pedidos
- Visitas
- Histórico

CNPJ continua sendo o identificador comercial principal. Duplicidades não são mescladas automaticamente. Empresa sem CNPJ permanece cadastrável, porém marcada para conferência.

## Contatos

`contatos.php` permite buscar por pessoa, e-mail, telefone, empresa ou DNB ID e filtrar Contatos sem empresa.

`contato.php` contém:

- Dados
- Empresa
- Negociações
- Marketing
- Visitas
- Histórico

A aba Marketing fica deliberadamente vazia de dados externos até a conexão real com o RD Station.

## Negociações

`negociacoes.php` abre por padrão em Lista e também oferece Kanban.

Filtros disponíveis:

- busca
- status
- categoria
- funil
- etapa
- empresa
- contato
- responsável
- origem
- período de movimentação
- valor mínimo e máximo

A área Leads / Pré-vendas lista contatos que ainda não participam de negociação.

`negociacao.php` concentra:

- empresa
- contatos e papéis
- propostas/orçamentos
- pedidos
- visitas
- valor estimado
- categoria
- funil
- etapa
- responsável
- status e motivo de perda

Papéis de contatos: principal, decisor, comprador, técnico, financeiro e outro.

## Propostas / Orçamentos

O fluxo legado de Orçamentos é preservado por compatibilidade, inclusive tarefas e cadência.

Ao criar uma proposta a partir de uma Negociação, `negotiation_id` é preservado tanto no POST tradicional quanto no endpoint AJAX.

As Negociações 1:1 criadas na migração da Fase 1 podem ser consolidadas com segurança: Pedido, Visita e contatos relacionados acompanham a proposta. Uma proposta que já pertence a uma negociação real não pode ser movida silenciosamente.

## Pedidos

Na operação normal, entrar em Fechamento:

1. marca a proposta como Ganha;
2. sincroniza a Negociação;
3. deixa `order_status=pending`;
4. não cria Pedido automaticamente.

O Pedido só é criado após ação explícita **Gerar Pedido**. Quando criado, a Negociação passa para `order_status=generated`.

A exceção futura de importação histórica do RD Station será tratada na fase de sincronização, conforme o levantamento aprovado.

## RBAC

Empresas e Contatos recebem `created_by_user_id` para que um vendedor consiga continuar vendo registros que acabou de criar antes de existir uma Negociação.

Administrador vê todos os registros. Supervisores/Vendedores seguem o escopo de equipe e os vínculos comerciais.

## Migração

Aplicar após a Fase 1:

`sql/migrations/20260918_crm_phase2_ui_ownership.sql`

Ela adiciona a propriedade dos cadastros e marca empresas legadas sem CNPJ como pendentes de conferência.
