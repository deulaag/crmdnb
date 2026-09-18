# CRM DNB — Fase 7: Painel e relatórios consolidados

A Fase 7 incorpora as **Negociações** do novo núcleo CRM ao Painel e à Auditoria, sem misturar entidades que possuem significados comerciais diferentes.

## Regra principal

**Negociação não é Proposta.**

Uma Negociação criada ou importada do RD Station:

- aparece no módulo Negociações;
- aparece nos indicadores próprios do Painel;
- aparece na Auditoria mensal;
- não é somada ao KPI **Propostas emitidas**;
- não cria Orçamento artificial;
- não cria Pedido automaticamente.

O KPI de Propostas continua vindo exclusivamente de `orcamentos`.

## Painel

Abaixo da visão comercial existente, o Painel passa a apresentar um bloco específico de Negociações com:

- movimentadas na competência;
- criadas na competência;
- ganhas na competência de fechamento;
- perdidas na competência de fechamento;
- quantidade/valor com origem RD;
- ganhos sem Pedido DNB vinculado;
- resumo por Funil e Etapa.

O escopo de acesso continua usando `assigned_to_user_id`, portanto vendedor e supervisor veem somente seus registros/equipe e o ADM vê o conjunto completo.

## Competência

Para a lista de Negociações do mês, entram negócios:

- movimentados pelo campo `business_updated_at` / data comercial disponível; ou
- encerrados no mês pelo `closed_at`.

Um negócio ganho ou perdido é contado como ganho/perda no mês de `closed_at`. Se o RD não fornecer `closed_at`, a data de movimentação é usada como fallback.

Essa regra evita que uma Negociação ganha seja contabilizada novamente a cada atualização posterior.

## Ganhos sem Pedido

Quando uma Negociação está ganha mas não possui Pedido DNB relacionado, o Painel e a Auditoria mostram isso como **indicador de acompanhamento**.

Nenhum Pedido é criado pela Fase 7.

## Auditoria mensal

**Auditoria → Relatório do Painel** passa a incluir, por competência:

- indicadores de Negociações;
- tabela de Negociações;
- consolidação por Funil/Etapa;
- propostas/orçamentos;
- pedidos;
- visitas/prospecções;
- apuração por colaborador.

Os números de Propostas, Pedidos, Visitas e Comissões continuam usando o motor já existente do CRM.

## PDF

O PDF mensal agora possui um bloco de Negociações na capa e páginas próprias para:

- Negociações;
- Negociações por Funil/Etapa;
- Propostas;
- Pedidos;
- Visitas;
- Apuração por colaborador.

O documento informa que Negociações não compõem o KPI Propostas e que ganhos importados não criam Pedido automaticamente.

## CSV

Foi adicionada a exportação:

`/auditoria-relatorios-csv.php?mes=YYYY-MM`

Ela é exclusiva para ADM e contém seções independentes para:

- resumo de Negociações;
- Negociações detalhadas;
- Propostas/Orçamentos;
- Pedidos;
- Visitas/Prospecções.

O arquivo usa UTF-8 com BOM e separador ponto e vírgula para facilitar a abertura no Excel em ambiente pt-BR.

## Sem relatório paralelo de divergência

A Fase 7 não cria um relatório separado de “RD x DNB”. O RD é origem/sincronização; a entidade interna continua sendo o núcleo CRM DNB com `dnb_id`.

Conflitos de sincronização continuam sendo tratados na Fase 6, dentro da configuração RD.

## Sem nova migração

A Fase 7 usa as tabelas criadas nas Fases 1–6 e não exige nova alteração de banco.
