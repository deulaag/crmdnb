# CRM DNB — Fase 8: operação, auditoria e reprocessamento RD

A Fase 8 fecha a parte operacional da integração RD Station.

As Fases 6 e 7 já anteciparam sincronização automática, webhooks, PDF e CSV comercial. Esta fase concentra o que ainda faltava do levantamento original: **saúde da integração, erros, tentativas esgotadas, reprocessamento seletivo e trilha de auditoria**.

## Migração

Aplicar depois das Fases 5 e 6:

`sql/migrations/20260918_rd_phase8_ops_audit.sql`

Ela adiciona aos eventos de webhook:

- quantidade de reaberturas;
- data da última reabertura;
- usuário que pediu a última reabertura.

Também cria `rd_reprocess_actions`, que registra as ações administrativas de operação da integração.

## Central operacional

Em **Configurações → RD Station**, a Fase 8 mostra:

- estado geral;
- conexão RD Marketing;
- conexão RD CRM;
- webhooks ativos/esperados;
- fila aguardando;
- erros ainda dentro do retry automático;
- erros com tentativas esgotadas;
- jobs com erro;
- jobs possivelmente travados;
- conflitos pendentes;
- último webhook recebido/processado;
- última sincronização incremental concluída;
- checkpoint atual.

A classificação de saúde é baseada nesses sinais concretos; não altera dados comerciais.

## Reprocessar somente erros

O botão **Reprocessar somente erros** atua exclusivamente em eventos com `status=error`.

Ele:

1. preserva o payload original;
2. reinicia as tentativas do evento;
3. recoloca o evento em `pending`;
4. registra a data da reabertura;
5. registra o usuário ADM;
6. cria uma entrada em `rd_reprocess_actions`.

Eventos já processados ou ignorados não entram novamente na fila.

Também é possível reprocessar um único evento.

## Processar fila agora

O botão **Processar fila agora** usa o mesmo motor idempotente da Fase 6 e processa um lote de até 20 eventos.

Isso não substitui o cron; serve para operação e validação manual.

## Jobs com erro

Jobs de primeira carga ou incremental podem ser retomados do checkpoint salvo.

Jobs do tipo `webhook` não são retomados como uma sincronização comum, pois o objeto recuperável é o próprio evento da fila. Nesse caso, o administrador usa o reprocessamento do evento.

## CSV de auditoria operacional

Novo endpoint ADM:

`/auditoria-rd-csv.php`

A exportação contém:

- fotografia da saúde da integração;
- execuções RD;
- eventos webhook;
- conflitos;
- ações de reprocessamento.

Por segurança, a exportação **não inclui**:

- `payload_json` bruto;
- access token;
- refresh token;
- Client Secret;
- token do webhook;
- token do cron.

O CSV usa UTF-8 com BOM e `;` para facilitar Excel pt-BR.

## Log de Fluxos

A rota `auditoria.php` agora aplica no próprio endpoint a mesma regra já mostrada no menu: **somente ADMINISTRADOR**.

Assim, não basta conhecer a URL para um Supervisor ou Suporte abrir o módulo de auditoria.

## Pedidos e Orçamentos

A Fase 8 é operacional.

Ela não:

- cria Pedido;
- cria Orçamento;
- altera valores comerciais para “corrigir” uma falha;
- mescla CNPJ/e-mail automaticamente.

O reprocessamento executa novamente as mesmas regras de identidade, fonte oficial e conflitos definidas nas fases anteriores.
