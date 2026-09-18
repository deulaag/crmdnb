# CRM DNB — Fase 6: sincronização contínua do RD Station

A Fase 6 transforma a carga inicial da Fase 5 em uma integração contínua. O desenho usa **webhooks para baixa latência** e uma **sincronização incremental por `updated_at` como rede de segurança** no RD Station CRM.

## Migração

Aplicar depois das Fases 1–5:

`sql/migrations/20260918_rd_phase6_incremental_webhooks.sql`

A migração:

- habilita jobs `incremental` e `webhook`;
- cria `rd_sync_state` para checkpoints;
- cria `rd_webhook_events` para fila idempotente;
- cria `rd_webhook_subscriptions` para auditoria das assinaturas;
- adiciona estado remoto em `crm_external_links`;
- adiciona usuário/data/ação na resolução de conflitos.

## Fluxo operacional

### Webhook

O callback público é:

`/api/webhooks/rdstation/index.php`

O endpoint valida o segredo, valida o JSON e **somente enfileira** o evento. Ele não faz importação pesada durante a chamada externa.

O processamento real ocorre pelo executor interno, reduzindo o risco de timeout e permitindo reprocessamento.

Eventos repetidos são deduplicados por `transaction_uuid` no CRM ou por hash determinístico do payload no Marketing.

### Cron

O executor interno é:

`/api/internal/rdstation/sync.php`

Ele exige um token diferente do token de webhook.

Em **Configurações → RD Station → Fase 6**, o CRM mostra um comando pronto para o Cron Job do cPanel depois que a automação é salva.

Recomendação de execução no cPanel: a cada 5 minutos. O CRM aplica internamente o intervalo configurado para a carga incremental, então executar o cron com frequência também permite esvaziar rapidamente a fila de webhooks.

## Sincronização incremental

A incremental não repete a carga completa.

O CRM mantém o checkpoint `crm_incremental_checkpoint` e consulta:

1. Empresas alteradas desde o checkpoint;
2. Contatos alterados desde o checkpoint;
3. Negociações alteradas desde o checkpoint.

Cada job possui um `cutoff_at` fixo. O checkpoint só avança para esse corte quando todas as etapas terminam.

Também existe uma sobreposição configurável, padrão de 10 minutos, para reduzir risco de perder registros na fronteira temporal. Como os upserts são idempotentes, reler uma pequena janela é seguro.

## Eventos do RD CRM

A Fase 6 prepara assinaturas para:

- Empresa criada, atualizada e excluída;
- Contato criado, atualizado e excluído;
- Negociação criada, atualizada e excluída.

O CRM DNB usa autenticação por cabeçalho customizado para esses webhooks.

## Eventos do RD Marketing

A Fase 6 prepara:

- `WEBHOOK.CONVERTED`;
- `WEBHOOK.MARKED_OPPORTUNITY`.

As relações `COMPANY` e `CONTACT_FUNNEL` são solicitadas quando disponíveis.

**Limitação importante do RD Marketing:** importações e atualizações manuais do contato não acionam webhooks. Portanto, esses webhooks não devem ser tratados como uma réplica completa de toda edição manual feita no Marketing. A automação desta fase usa os eventos oficialmente disponíveis; uma reconciliação manual/completa da base continua disponível pela lógica da Fase 5 quando necessário.

## Exclusões

Uma exclusão recebida do RD CRM **não executa DELETE no CRM DNB**.

O sistema:

1. marca o vínculo externo como `remote_status=deleted`;
2. grava `remote_deleted_at`;
3. cria conflito de exclusão remota;
4. pede decisão administrativa.

Para Empresa e Contato, o administrador pode manter o registro DNB ou desativá-lo. Para Negociação, o histórico é preservado.

## Resolução de conflitos

O Painel RD passa a permitir ações explícitas:

- **Manter DNB** — preserva o valor/registro local;
- **Usar RD** — aplica o valor remoto em conflitos de campo;
- **Vincular** — associa o ID externo a um candidato DNB permitido;
- **Desativar DNB** — apenas para Empresa/Contato removidos no RD;
- **Ignorar** — encerra o conflito sem alterar o dado comercial.

Toda resolução grava ação, usuário, data, observação e `resolved_dnb_id` quando aplicável.

Não há fusão silenciosa de CNPJ ou e-mail.

## Ganhos históricos e Pedidos

As regras das fases anteriores continuam:

- negócio importado do RD pode ficar como `won`;
- `origin=rd_crm`;
- nenhum Pedido é criado automaticamente;
- nenhum Pedido é excluído pelo sincronizador;
- Pedido local continua sendo uma ação do CRM DNB.

## Configuração no ADM

Depois da migração:

1. abra **Configurações → RD Station**;
2. conclua a Fase 5 ao menos uma vez para existir um checkpoint inicial;
3. na Fase 6, marque **Sincronização recorrente ativa**;
4. escolha intervalo e sobreposição;
5. salve a automação para gerar os segredos locais;
6. clique em **Registrar/atualizar** webhooks;
7. copie o comando mostrado e configure o Cron Job do cPanel a cada 5 minutos.

A tabela de assinaturas mostra o HTTP e o erro retornado pelo RD se algum evento não puder ser registrado. Isso é importante porque permissões e disponibilidade de eventos dependem do produto/plano/aplicativo conectado.

## Segurança

- segredos ficam em `integrations.local.php`, fora do Git;
- webhook e cron usam segredos distintos;
- o endpoint público limita o payload a 1 MB;
- processamento externo não faz alterações pesadas durante a requisição do webhook;
- eventos são idempotentes;
- exclusões são não destrutivas;
- conflitos precisam de ação explícita do administrador.
