# CRM DNB — Fase 1: núcleo comercial

Esta fase introduz a estrutura definitiva que será usada pelas próximas etapas da integração RD Station sem alterar a navegação atual.

## Identidade DNB

Cada entidade recebe um `dnb_id` próprio e estável:

- Empresa: `EMP-000001`
- Contato: `CON-000001`
- Negociação: `NEG-000001`
- Orçamento/Proposta: `ORC-000001`
- Pedido: `PED-000001`
- Visita: `VIS-000001`

IDs de sistemas externos não são adicionados às tabelas comerciais. Eles ficam em `crm_external_links`, relacionando o identificador remoto ao `dnb_id`.

## Empresas

A tabela existente `crm_companies` foi mantida para não quebrar OS, visitas e oportunidades existentes.

O CNPJ é o identificador comercial principal, mas não é mais uma restrição UNIQUE. Duplicidades são marcadas com `review_status=needs_review` para conferência humana.

## Contatos

A tabela existente `crm_contacts` também foi preservada.

O e-mail é o identificador comercial principal. Um contato pertence a no máximo uma empresa, porém `company_id` pode ser nulo para suportar a fila **Contatos sem empresa**. Duplicidades de e-mail são sinalizadas para revisão.

## Negociações

A nova tabela `crm_negotiations` representa o negócio comercial acima da proposta/orçamento.

Uma negociação:
- pertence opcionalmente a uma empresa;
- possui uma única categoria;
- pode ter vários contatos, com papéis principal, decisor, comprador, técnico, financeiro ou outro;
- pode possuir vários Orçamentos/Propostas;
- pode possuir vários Pedidos;
- pode receber Visitas;
- guarda valor estimado separado do valor da proposta;
- possui estados open/won/lost e pedido none/pending/generated.

A associação de contatos é feita por `crm_negotiation_contacts`.

## Compatibilidade com o CRM atual

Durante a transição, cada Orçamento existente recebe inicialmente uma Negociação 1:1. Isso impede perda de dados e mantém o CRM atual operacional. Na Fase 2 a nova interface passa a trabalhar diretamente com Negociações e poderá reunir propostas do mesmo negócio.

Novos Orçamentos criados pela UI antiga também recebem uma Negociação automaticamente quando a migração da Fase 1 estiver instalada.

## Migração

Arquivo:

`sql/migrations/20260918_crm_core_entities_phase1.sql`

A migração é idempotente para os principais objetos e:
1. evolui Empresas/Contatos já existentes;
2. adiciona dnb_id;
3. cria Negociações e relações;
4. cria o mapa técnico de IDs externos;
5. vincula Orçamentos, Pedidos e Visitas;
6. faz backfill dos registros existentes;
7. marca CNPJs/e-mails duplicados para revisão.

Não há OAuth, sincronização RD Station, Funis importados ou novos menus nesta fase. Esses itens pertencem às fases seguintes.
